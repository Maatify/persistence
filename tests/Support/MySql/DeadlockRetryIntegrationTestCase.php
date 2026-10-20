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

    protected static PDO $pdo;
    protected static PDO $controlPdo;
    protected static DeadlockRetrySchemaManager $schemaManager;

    /** @var list<array{resource, array<int, resource>}> */
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
        [$process, $pipes] = $this->startPartner();
        $this->partners[] = [$process, $pipes];

        $ready = $this->readPartnerLine($pipes);
        self::assertStringStartsWith('READY ', $ready);

        $this->lockRow($victim, 1);
        fwrite($pipes[0], "go\n");
        fflush($pipes[0]);
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
        foreach ($this->partners as [, $pipes]) {
            $this->partnerReports[] = $this->readPartnerLine($pipes);
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

    /** @return array{resource, array<int, resource>} */
    private function startPartner(): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/DeadlockPartner.php', '1', '2'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        /** @var array<int, resource> $openedPipes */
        $openedPipes = $pipes;
        $stdin = $openedPipes[0] ?? null;
        $stdout = $openedPipes[1] ?? null;
        $stderr = $openedPipes[2] ?? null;

        if (!is_resource($process) || !is_resource($stdin) || !is_resource($stdout) || !is_resource($stderr)) {
            throw new AssertionFailedError('Unable to start the deadlock partner process.');
        }

        return [$process, [0 => $stdin, 1 => $stdout, 2 => $stderr]];
    }

    /** @param array<int, resource> $pipes */
    private function readPartnerLine(array $pipes): string
    {
        stream_set_timeout($pipes[1], 30);
        $line = fgets($pipes[1]);

        if ($line === false) {
            throw new AssertionFailedError('Deadlock partner produced no output; stderr: ' . stream_get_contents($pipes[2]));
        }

        return trim($line);
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
        foreach ($this->partners as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_terminate($process);
            proc_close($process);
        }

        $this->partners = [];
    }

    private static function rollBackOpenTransaction(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
