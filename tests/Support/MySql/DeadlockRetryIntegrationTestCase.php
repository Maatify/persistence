<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\MySql;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * Real-MySQL base for the deadlock-retry runners. provokeDeadlockAsVictim()
 * makes the connection under test the victim of a genuine InnoDB deadlock
 * (driver code 1213, SQLSTATE 40001) against a DeadlockPartner process on its
 * own connection; no error is simulated. Rows 1 ("lock-a") and 2 ("lock-b")
 * are the contended rows; payloads committed by a test are read on a separate
 * control connection.
 */
abstract class DeadlockRetryIntegrationTestCase extends TestCase
{
    protected const DEADLOCK_DRIVER_CODE = 1213;
    protected const DEADLOCK_SQLSTATE = '40001';
    protected const PARTNER_READ_TIMEOUT_SECONDS = 30.0;

    protected static PDO $pdo;
    protected static PDO $controlPdo;
    protected static DeadlockRetrySchemaManager $schemaManager;

    /** @var list<PartnerProcess> */
    private array $partners = [];

    /** @var list<string> */
    private array $partnerReports = [];

    public static function setUpBeforeClass(): void
    {
        self::$pdo = (new MySqlConnectionFactory())->create();
        self::$controlPdo = (new MySqlConnectionFactory())->create();
        self::$schemaManager = new DeadlockRetrySchemaManager(self::$controlPdo);
        self::$schemaManager->initialize();
    }

    protected function setUp(): void
    {
        self::rollBackOpenTransaction(self::$pdo);
        self::$pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');
        self::$schemaManager->reset();
        $this->insertOn(self::$controlPdo, 'lock-a');
        $this->insertOn(self::$controlPdo, 'lock-b');
        $this->partners = [];
        $this->partnerReports = [];
    }

    protected function tearDown(): void
    {
        self::rollBackOpenTransaction(self::$pdo);
        $this->stopPartners();
        self::$schemaManager->reset();
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$pdo)) {
            self::rollBackOpenTransaction(self::$pdo);
        }

        if (isset(self::$schemaManager)) {
            self::$schemaManager->dropTable();
        }
    }

    protected function pdo(): PDO
    {
        return self::$pdo;
    }

    /**
     * Inside the current transaction of the victim connection (default: the
     * connection under test): lock row 1, let a heavier partner (holding row 2)
     * queue for row 1, then request row 2. InnoDB detects the cycle and rolls
     * back the victim's lighter transaction. Returns the genuine PDOException
     * that MySQL raised.
     */
    protected function provokeDeadlockAsVictim(?PDO $victim = null): PDOException
    {
        $victim ??= self::$pdo;
        $partner = PartnerProcess::deadlockPartner();
        $this->partners[] = $partner;

        $ready = $partner->readLine(self::PARTNER_READ_TIMEOUT_SECONDS);
        self::assertStringStartsWith('READY ', $ready);

        $this->lockRow($victim, 1);
        $partner->writeLine('go');
        $this->awaitBlockedLockingRead(substr($ready, strlen('READY ')));

        try {
            $this->lockRow($victim, 2);
        } catch (PDOException $exception) {
            self::assertSame(self::DEADLOCK_SQLSTATE, $exception->errorInfo[0] ?? null, $exception->getMessage());
            self::assertSame(self::DEADLOCK_DRIVER_CODE, $exception->errorInfo[1] ?? null, $exception->getMessage());

            return $exception;
        }

        self::fail('The victim connection was expected to be chosen as the deadlock victim.');
    }

    /** A new, separate MySQL connection (exception mode), closed when the test object is released. */
    protected function newConnection(): PDO
    {
        $pdo = (new MySqlConnectionFactory())->create();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');

        return $pdo;
    }

    /** Kills the server session of the given connection from the control connection (same MySQL user). */
    protected function killConnection(PDO $pdo): void
    {
        $id = $pdo->query('SELECT CONNECTION_ID()');
        self::assertInstanceOf(PDOStatement::class, $id);
        $connectionId = $id->fetchColumn();
        self::assertTrue(is_int($connectionId) || is_string($connectionId));
        self::$controlPdo->exec('KILL ' . (int) $connectionId);
        usleep(200_000);
    }

    protected function insert(string $payload): void
    {
        $this->insertOn(self::$pdo, $payload);
    }

    /**
     * Committed payloads other than the contended lock rows, read on the control connection.
     *
     * @return list<string>
     */
    protected function committedPayloads(): array
    {
        $statement = self::$controlPdo->query(
            'SELECT `payload` FROM `' . DeadlockRetrySchemaManager::TABLE . '` WHERE `id` > 2 ORDER BY `id`',
        );
        self::assertInstanceOf(PDOStatement::class, $statement);

        return array_values(array_map(
            static function (mixed $payload): string {
                self::assertIsString($payload);

                return $payload;
            },
            $statement->fetchAll(PDO::FETCH_COLUMN),
        ));
    }

    /**
     * Payloads other than the contended lock rows, as seen by the connection under test itself.
     *
     * @return list<string>
     */
    protected function payloadsSeenByConnectionUnderTest(): array
    {
        $statement = self::$pdo->query(
            'SELECT `payload` FROM `' . DeadlockRetrySchemaManager::TABLE . '` WHERE `id` > 2 ORDER BY `id`',
        );
        self::assertInstanceOf(PDOStatement::class, $statement);

        return array_values(array_map(
            static function (mixed $payload): string {
                self::assertIsString($payload);

                return $payload;
            },
            $statement->fetchAll(PDO::FETCH_COLUMN),
        ));
    }

    /**
     * Waits for every partner started by the test and returns their final reports.
     *
     * @return list<string>
     */
    protected function partnerReports(): array
    {
        foreach ($this->partners as $partner) {
            $this->partnerReports[] = $partner->readLine(self::PARTNER_READ_TIMEOUT_SECONDS);
        }

        $this->stopPartners();

        return $this->partnerReports;
    }

    protected function insertOn(PDO $pdo, string $payload): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO `' . DeadlockRetrySchemaManager::TABLE . '` (`payload`) VALUES (:payload)',
        );
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['payload' => $payload]);
    }

    private function lockRow(PDO $pdo, int $id): void
    {
        $statement = $pdo->prepare(
            'SELECT `id` FROM `' . DeadlockRetrySchemaManager::TABLE . '` WHERE `id` = :id FOR UPDATE',
        );
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['id' => $id]);
        $statement->fetchAll();
    }

    /**
     * Waits until the partner connection is stuck in its locking read, observed
     * on the control connection (SHOW FULL PROCESSLIST lists the threads of the
     * same MySQL user without the PROCESS privilege).
     */
    private function awaitBlockedLockingRead(string $connectionId): void
    {
        $deadline = microtime(true) + 15;

        do {
            $rows = self::$controlPdo->query('SHOW FULL PROCESSLIST');
            self::assertInstanceOf(PDOStatement::class, $rows);

            foreach ($rows->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $id = $row['Id'] ?? null;
                $info = $row['Info'] ?? null;
                $time = $row['Time'] ?? null;

                if (
                    is_scalar($id) && (string) $id === $connectionId
                    && is_string($info) && str_contains($info, 'FOR UPDATE')
                    && is_numeric($time) && (int) $time >= 1
                ) {
                    return;
                }
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new AssertionFailedError('The deadlock partner never blocked on its locking read.');
    }

    private function stopPartners(): void
    {
        $failure = null;

        foreach ($this->partners as $partner) {
            try {
                $partner->stop();
            } catch (AssertionFailedError $error) {
                $failure ??= $error;
            }
        }

        $this->partners = [];

        if ($failure !== null) {
            throw $failure;
        }
    }

    private static function rollBackOpenTransaction(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
