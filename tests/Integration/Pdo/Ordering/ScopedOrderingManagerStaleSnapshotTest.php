<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Pdo\Ordering;

use Maatify\Persistence\Exception\InvalidOrderingOperationException;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Tests\Support\MySql\MySqlConnectionFactory;
use Maatify\Persistence\Tests\Support\MySql\MySqlIntegrationTestCase;
use Maatify\Persistence\Tests\Support\MySql\OrderingFixture;
use Maatify\Persistence\Tests\Support\MySql\OrderingSchemaManager;
use Maatify\Persistence\Tests\Support\MySql\OrderingStateReader;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression protection for getNextPositionForUpdate() inside a caller-owned
 * transaction whose REPEATABLE READ snapshot is older than another connection's
 * commit, and for getNextPosition() staying a plain, lock-free read.
 */
final class ScopedOrderingManagerStaleSnapshotTest extends MySqlIntegrationTestCase
{
    private const LOCK_WAIT_TIMEOUT_ERROR = 1205;
    private const DEADLOCK_ERROR = 1213;

    private ScopedOrderingManager $manager;
    private PDO $otherPdo;
    private OrderingFixture $otherFixture;
    private OrderingStateReader $otherReader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        $this->manager = new ScopedOrderingManager();
        $this->otherPdo = (new MySqlConnectionFactory())->create();
        $this->otherPdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->otherFixture = new OrderingFixture($this->otherPdo);
        $this->otherReader = new OrderingStateReader($this->otherPdo);
    }

    protected function tearDown(): void
    {
        if ($this->otherPdo->inTransaction()) {
            $this->otherPdo->rollBack();
        }

        parent::tearDown();
    }

    public function testScopedNextPositionSeesRowCommittedAfterCallerSnapshot(): void
    {
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 1);

        self::assertSame(2, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));
    }

    public function testGlobalNextPositionSeesRowCommittedAfterCallerSnapshot(): void
    {
        $this->fixture->insertGlobal(1);
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertGlobal(2);
        $this->otherFixture->insertGlobal(3);

        self::assertSame(4, $this->manager->getNextPositionForUpdate($this->pdo(), $this->globalConfig()));
    }

    public function testNullableScopeNextPositionSeesRowCommittedAfterCallerSnapshot(): void
    {
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped(null, 1);
        $this->otherFixture->insertScoped('scope-a', 7);

        self::assertSame(2, $this->manager->getNextPositionForUpdate($this->pdo(), $this->nullableScopedConfig(), null));
    }

    public function testEmptyScopeStillReturnsOneInsideCallerTransaction(): void
    {
        $this->fixture->insertScoped('other-scope', 6);
        $this->beginTransactionWithSnapshot();

        self::assertSame(1, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));
        self::assertSame(1, $this->manager->getNextPositionForUpdate($this->pdo(), $this->globalConfig()));
    }

    public function testNextPositionIgnoresSoftDeletedRowsCommittedAfterCallerSnapshot(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 2);
        $this->otherFixture->insertScoped('scope-a', 9, deletedAt: '2026-01-01 00:00:00');

        self::assertSame(3, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));
    }

    public function testNextPositionIncludesSoftDeletedRowsWhenFilteringIsDisabledInsideCallerTransaction(): void
    {
        $this->fixture->insertGlobal(2);
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertGlobal(9, deletedAt: '2026-01-01 00:00:00');

        self::assertSame(10, $this->manager->getNextPositionForUpdate($this->pdo(), $this->globalConfigWithoutSoftDeletes()));
    }

    public function testNextPositionReflectsSoftDeleteCommittedAfterCallerSnapshot(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $topId = $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();

        $statement = $this->otherPdo->prepare(
            'UPDATE `' . OrderingSchemaManager::SCOPED_TABLE . '` SET `deleted_at` = :deleted_at WHERE `id` = :id'
        );
        self::assertNotFalse($statement);
        $statement->execute(['deleted_at' => '2026-01-01 00:00:00', 'id' => $topId]);

        self::assertSame(2, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));
    }

    public function testNextPositionStaysIndependentPerScopeInsideCallerTransaction(): void
    {
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 4);
        $this->otherFixture->insertScoped('scope-b', 8);

        self::assertSame(5, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));
        self::assertSame(9, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-b'));
    }

    public function testStaleSnapshotInsertAndMoveKeepLivePositionsUniqueAndContiguous(): void
    {
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 1);

        $position = $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a');
        $newId = $this->fixture->insertScoped('scope-a', $position);
        $this->pdo()->commit();

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $newId, 1));

        $orders = array_values($this->otherReader->scopedOrdersById('scope-a'));
        sort($orders);
        self::assertSame([1, 2], $orders);
    }

    public function testLockingReadRequiresAnActiveTransactionAndDoesNotBeginOne(): void
    {
        $this->fixture->insertGlobal(1);

        try {
            $this->manager->getNextPositionForUpdate($this->pdo(), $this->globalConfig());
            self::fail('A locking next-position read outside a transaction must be rejected.');
        } catch (InvalidOrderingOperationException $exception) {
            self::assertSame('A locking next-position read requires an active PDO transaction.', $exception->getMessage());
        }

        self::assertFalse($this->pdo()->inTransaction(), 'The manager must not begin a transaction.');
    }

    public function testLockingReadPreservesCallerTransactionOwnership(): void
    {
        $this->fixture->insertGlobal(1);

        $this->pdo()->beginTransaction();
        self::assertSame(2, $this->manager->getNextPositionForUpdate($this->pdo(), $this->globalConfig()));
        self::assertTrue($this->pdo()->inTransaction(), 'The manager must not commit a caller-owned transaction.');

        $this->pdo()->rollBack();
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testLockingReadRejectsInconsistentScopeUsage(): void
    {
        $this->pdo()->beginTransaction();

        try {
            $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig());
            self::fail('A scoped configuration without a scope value must be rejected.');
        } catch (InvalidOrderingOperationException) {
            self::assertTrue($this->pdo()->inTransaction());
        }

        try {
            $this->manager->getNextPositionForUpdate($this->pdo(), $this->globalConfig(), 'unexpected-scope');
            self::fail('A global configuration with a scope value must be rejected.');
        } catch (InvalidOrderingOperationException) {
            self::assertTrue($this->pdo()->inTransaction());
        }
    }

    public function testPlainReadDoesNotBeginTransactionOutsideOne(): void
    {
        $this->fixture->insertGlobal(1);

        self::assertSame(2, $this->manager->getNextPosition($this->pdo(), $this->globalConfig()));
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testCallerTransactionHoldsLastRowLockUntilCallerEnds(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();

        self::assertSame(3, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));

        // A concurrent allocator for the same non-empty scope is serialized.
        $this->otherPdo->beginTransaction();

        try {
            $this->manager->getNextPositionForUpdate($this->otherPdo, $this->scopedConfig(), 'scope-a');
            self::fail('Concurrent allocation in a locked scope must wait for the caller transaction.');
        } catch (PDOException $exception) {
            self::assertSame(self::LOCK_WAIT_TIMEOUT_ERROR, $exception->errorInfo[1] ?? null);
        }

        $this->otherPdo->rollBack();

        // Once the caller ends its transaction the next allocator proceeds.
        $newId = $this->fixture->insertScoped('scope-a', 3);
        $this->pdo()->commit();

        $this->otherPdo->beginTransaction();
        self::assertSame(4, $this->manager->getNextPositionForUpdate($this->otherPdo, $this->scopedConfig(), 'scope-a'));
        $this->otherPdo->rollBack();

        self::assertSame(3, $this->otherReader->scopedOrdersById('scope-a')[$newId]);
    }

    public function testCallerTransactionLockBlocksConcurrentMoveWithinScope(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $topId = $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();
        $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a');

        try {
            $this->manager->moveWithinScope($this->otherPdo, $this->scopedConfig(), 'scope-a', $topId, 1);
            self::fail('moveWithinScope() must wait for the allocator transaction holding the scope tail.');
        } catch (PDOException $exception) {
            self::assertSame(self::LOCK_WAIT_TIMEOUT_ERROR, $exception->errorInfo[1] ?? null);
        }

        self::assertFalse($this->otherPdo->inTransaction());
        self::assertSame(2, $this->otherReader->scopedOrdersById('scope-a')[$topId]);
    }

    public function testConcurrentMoveWithinScopeBlocksCallerTransactionAllocation(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $topId = $this->fixture->insertScoped('scope-a', 2);

        $statement = $this->pdo()->query('SELECT @@SESSION.innodb_lock_wait_timeout');
        self::assertNotFalse($statement);
        $originalLockWaitTimeout = $statement->fetchColumn();
        self::assertIsNumeric($originalLockWaitTimeout);

        try {
            $this->otherPdo->beginTransaction();
            self::assertTrue($this->manager->moveWithinScope($this->otherPdo, $this->scopedConfig(), 'scope-a', $topId, 1));

            try {
                $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');

                try {
                    $this->pdo()->beginTransaction();

                    try {
                        $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a');
                        self::fail('getNextPositionForUpdate() must wait for a move that holds the scope lock.');
                    } catch (PDOException $exception) {
                        self::assertSame(self::LOCK_WAIT_TIMEOUT_ERROR, $exception->errorInfo[1] ?? null);
                    }
                } finally {
                    if ($this->pdo()->inTransaction()) {
                        $this->pdo()->rollBack();
                    }
                }
            } finally {
                $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = ' . (int) $originalLockWaitTimeout);
            }
        } finally {
            if ($this->otherPdo->inTransaction()) {
                $this->otherPdo->rollBack();
            }
        }
    }

    public function testWaitingAllocatorSeesHigherTailCommittedWhileItWaitedUnderReadCommitted(): void
    {
        [$insertCommitted, $report] = $this->runWaitingAllocatorBehindTailInsert('READ COMMITTED');

        self::assertTrue($insertCommitted);
        self::assertSame('POSITION 4', $report);
        self::assertSame([1, 2, 3], $this->committedScopeAOrders());
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function waitingAllocatorWeights(): array
    {
        return [
            // Usually the queued allocator becomes the deadlock victim.
            'light waiting allocator' => [[]],
            // Biased towards the inserting transaction becoming the victim instead.
            'heavy waiting allocator' => [['heavy']],
        ];
    }

    /**
     * @param list<string> $waiterFlags
     */
    #[DataProvider('waitingAllocatorWeights')]
    public function testWaitingAllocatorNeverReusesAnOccupiedPositionUnderRepeatableRead(array $waiterFlags): void
    {
        [$insertCommitted, $report] = $this->runWaitingAllocatorBehindTailInsert('REPEATABLE READ', $waiterFlags);

        // Whichever transaction InnoDB picks as the deadlock victim, the committed
        // state must match the outcome: the inserted row exists only if its
        // transaction committed, and no position is occupied twice.
        $orders = $this->committedScopeAOrders();
        self::assertSame($insertCommitted ? [1, 2, 3] : [1, 2], $orders);

        if ($report === 'FAILURE ' . self::DEADLOCK_ERROR) {
            // Only one transaction can be the deadlock victim.
            self::assertTrue($insertCommitted);

            return;
        }

        // Otherwise the queued allocator must report exactly the next free position.
        self::assertSame('POSITION ' . (max($orders) + 1), $report);
    }

    public function testRepeatedLockingReadKeepsEveryTailItLockedUntilTheTransactionEnds(): void
    {
        $this->runWaitingAllocatorBehindTailInsert('READ COMMITTED', ['hold'], function (string $report): void {
            self::assertSame('POSITION 4', $report);

            // The waiting allocator locked the old tail (order 2), then the new tail
            // (order 3); both stay locked while its transaction is open. Earlier
            // rows are not part of its lock footprint.
            foreach ([2, 3] as $order) {
                try {
                    $this->probeScopeARowLock($order);
                    self::fail('The row at order ' . $order . ' must stay locked by the waiting allocator.');
                } catch (PDOException $exception) {
                    self::assertSame(self::LOCK_WAIT_TIMEOUT_ERROR, $exception->errorInfo[1] ?? null);
                }
            }

            self::assertSame(1, $this->probeScopeARowLock(1));
        });
    }

    public function testPlainReadStaysStaleWhenTheCallerLocksAfterTheSnapshotWasEstablished(): void
    {
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 1);

        // A locking read sees the committed row but does not refresh the snapshot.
        $statement = $this->pdo()->query(
            'SELECT `id` FROM `' . OrderingSchemaManager::SCOPED_TABLE . '` WHERE `scope_key` = \'scope-a\' FOR UPDATE'
        );
        self::assertNotFalse($statement);
        self::assertCount(1, $statement->fetchAll());

        // Repeating the plain read in the same transaction (a retry) keeps returning the stale value.
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            self::assertSame(
                1,
                $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'),
                'Plain read attempt ' . $attempt,
            );
        }

        self::assertSame(2, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));
    }

    public function testPlainReadSeesLatestCommittedWhenTheSerializationLockPrecedesTheSnapshot(): void
    {
        $this->fixture->insertGlobal(1);

        $this->pdo()->beginTransaction();

        // The serialization lock (here on an unrelated row) is the first statement,
        // so the snapshot is created by the plain read below, after the lock.
        $statement = $this->pdo()->query('SELECT `id` FROM `' . OrderingSchemaManager::GLOBAL_TABLE . '` FOR UPDATE');
        self::assertNotFalse($statement);
        $statement->fetchAll();

        $this->otherFixture->insertScoped('scope-a', 1);

        self::assertSame(2, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));
    }

    public function testCallerTransactionLockDoesNotBlockRowsOfOtherScopes(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->fixture->insertScoped('scope-a', 2);
        $otherId = $this->fixture->insertScoped('scope-0', 1);
        $this->beginTransactionWithSnapshot();

        $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a');

        $statement = $this->otherPdo->prepare(
            'UPDATE `' . OrderingSchemaManager::SCOPED_TABLE . '` SET `label` = :label WHERE `id` = :id'
        );
        self::assertNotFalse($statement);
        $statement->execute(['label' => 'updated outside locked scope', 'id' => $otherId]);

        self::assertSame(1, $statement->rowCount());
    }

    public function testPlainReadTakesNoLockEvenInsideCallerTransaction(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $topId = $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();

        self::assertSame(3, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));

        // With the one-second lock wait timeout of the other connection, any
        // lock held by the plain read would make one of these fail with 1205.
        $update = $this->otherPdo->prepare(
            'UPDATE `' . OrderingSchemaManager::SCOPED_TABLE . '` SET `label` = :label WHERE `id` = :id'
        );
        self::assertNotFalse($update);
        $update->execute(['label' => 'updated while plain read is in a transaction', 'id' => $topId]);
        self::assertSame(1, $update->rowCount());

        $this->otherFixture->insertScoped('scope-a', 3);
        self::assertSame([1, 2, 3], array_values($this->otherReader->scopedOrdersById('scope-a')));
    }

    public function testPlainReadOutsideTransactionIsNotBlockedByAnotherConnectionsLock(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->fixture->insertScoped('scope-a', 2);

        $statement = $this->pdo()->query('SELECT @@SESSION.innodb_lock_wait_timeout');
        self::assertNotFalse($statement);
        $originalLockWaitTimeout = $statement->fetchColumn();
        self::assertIsNumeric($originalLockWaitTimeout);

        try {
            $this->otherPdo->beginTransaction();
            $this->otherPdo->query(
                'SELECT `id` FROM `' . OrderingSchemaManager::SCOPED_TABLE . '`
                  WHERE `scope_key` = \'scope-a\' ORDER BY `display_order` DESC FOR UPDATE'
            );

            try {
                // Would hit the lock wait timeout if this call used a locking read.
                $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');

                try {
                    self::assertSame(3, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));
                    self::assertFalse($this->pdo()->inTransaction());
                } finally {
                    if ($this->pdo()->inTransaction()) {
                        $this->pdo()->rollBack();
                    }
                }
            } finally {
                $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = ' . (int) $originalLockWaitTimeout);
            }
        } finally {
            if ($this->otherPdo->inTransaction()) {
                $this->otherPdo->rollBack();
            }
        }
    }

    /**
     * @param list<string> $flags
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function startWaitingAllocator(string $isolationLevel, string $scope, array $flags): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/Support/MySql/NextPositionWaiter.php', $isolationLevel, $scope, ...$flags],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        /** @var array<int, resource> $openedPipes */
        $openedPipes = $pipes;
        $stdin = $openedPipes[0] ?? null;
        $stdout = $openedPipes[1] ?? null;
        $stderr = $openedPipes[2] ?? null;

        if (!is_resource($process) || !is_resource($stdin) || !is_resource($stdout) || !is_resource($stderr)) {
            self::fail('Unable to start the waiting allocator process.');
        }

        return [$process, [0 => $stdin, 1 => $stdout, 2 => $stderr]];
    }

    /**
     * Holds the scope tail in the caller transaction, queues a second allocator
     * behind it on its own connection, inserts a higher tail and commits while
     * the second allocator waits. Returns whether the insert was committed and
     * the second allocator's report. A deadlock on the inserting transaction is
     * tolerated (REPEATABLE READ only); any other failure propagates.
     *
     * $whileWaiterOpen runs after the report is read and before the waiting
     * allocator is stopped, so a test can inspect the locks it still holds when
     * it was started with the "hold" flag.
     *
     * @param list<string>                $waiterFlags
     * @param (callable(string): void)|null $whileWaiterOpen
     *
     * @return array{0: bool, 1: string}
     */
    private function runWaitingAllocatorBehindTailInsert(
        string $isolationLevel,
        array $waiterFlags = [],
        ?callable $whileWaiterOpen = null,
    ): array {
        $this->fixture->insertScoped('scope-a', 1);
        $this->fixture->insertScoped('scope-a', 2);

        $process = null;
        $pipes = [];

        try {
            try {
                $this->pdo()->exec('SET TRANSACTION ISOLATION LEVEL ' . $isolationLevel);
                $this->pdo()->beginTransaction();

                // The first allocator locks the current tail (order 2).
                self::assertSame(3, $this->manager->getNextPositionForUpdate($this->pdo(), $this->scopedConfig(), 'scope-a'));

                [$process, $pipes] = $this->startWaitingAllocator($isolationLevel, 'scope-a', $waiterFlags);

                $connectionId = $this->readWaiterLine($pipes);
                self::assertStringStartsWith('CONNECTION_ID ', $connectionId);
                $this->awaitBlockedLockingRead(substr($connectionId, strlen('CONNECTION_ID ')));

                // The first allocator inserts the new tail and commits while the second waits.
                $insertCommitted = false;

                try {
                    $this->fixture->insertScoped('scope-a', 3);
                    $this->pdo()->commit();
                    $insertCommitted = true;
                } catch (PDOException $exception) {
                    if (($exception->errorInfo[1] ?? null) !== self::DEADLOCK_ERROR) {
                        throw $exception;
                    }
                }

                $report = $this->readWaiterLine($pipes);

                if ($whileWaiterOpen !== null) {
                    $whileWaiterOpen($report);
                }

                return [$insertCommitted, $report];
            } finally {
                if ($this->pdo()->inTransaction()) {
                    $this->pdo()->rollBack();
                }
            }
        } finally {
            if ($process !== null) {
                $this->stopWaitingAllocator($process, $pipes);
            }
        }
    }

    /**
     * Orders of the committed rows of scope-a, read on the other connection.
     *
     * @return list<int>
     */
    private function committedScopeAOrders(): array
    {
        $orders = array_values($this->otherReader->scopedOrdersById('scope-a'));
        sort($orders);

        return $orders;
    }

    /**
     * Tries to lock the scope-a row at the given order on the other connection
     * (one-second lock wait timeout) and returns how many rows it locked.
     */
    private function probeScopeARowLock(int $order): int
    {
        $statement = $this->otherPdo->prepare(
            'SELECT `id` FROM `' . OrderingSchemaManager::SCOPED_TABLE . '`
              WHERE `scope_key` = \'scope-a\' AND `display_order` = :display_order FOR UPDATE'
        );
        self::assertNotFalse($statement);
        $statement->execute(['display_order' => $order]);

        return count($statement->fetchAll());
    }

    /**
     * @param array<int, resource> $pipes
     */
    private function readWaiterLine(array $pipes): string
    {
        stream_set_timeout($pipes[1], 30);
        $line = fgets($pipes[1]);

        if ($line === false) {
            self::fail('Waiting allocator produced no output; stderr: ' . stream_get_contents($pipes[2]));
        }

        return trim($line);
    }

    /**
     * Waits until the given connection is stuck inside a locking read, using
     * the other connection so the observation does not touch the caller's transaction.
     */
    private function awaitBlockedLockingRead(string $connectionId): void
    {
        $deadline = microtime(true) + 15;

        do {
            $rows = $this->otherPdo->query('SHOW FULL PROCESSLIST');
            self::assertNotFalse($rows);

            foreach ($rows->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $id = $row['Id'] ?? null;
                $command = $row['Command'] ?? null;
                $info = $row['Info'] ?? null;
                $time = $row['Time'] ?? null;

                if (
                    is_scalar($id) && (string) $id === $connectionId
                    && in_array($command, ['Query', 'Execute'], true)
                    && is_string($info) && str_contains($info, 'FOR UPDATE')
                    && is_numeric($time) && (int) $time >= 1
                ) {
                    return;
                }
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::fail('The second allocator never blocked on the locking read.');
    }

    /**
     * @param resource             $process
     * @param array<int, resource> $pipes
     */
    private function stopWaitingAllocator($process, array $pipes): void
    {
        try {
            proc_terminate($process);
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            proc_close($process);
        }
    }

    private function beginTransactionWithSnapshot(): void
    {
        $this->pdo()->beginTransaction();

        // Establish the REPEATABLE READ consistent-read snapshot before any
        // other connection commits.
        $this->countRows(OrderingSchemaManager::SCOPED_TABLE);
        $this->countRows(OrderingSchemaManager::GLOBAL_TABLE);
    }

    /**
     * @param non-empty-string $table
     */
    private function countRows(string $table): void
    {
        $statement = $this->pdo()->query('SELECT COUNT(*) FROM `' . $table . '`');
        self::assertNotFalse($statement);
        $statement->fetchColumn();
    }

    private function globalConfig(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(table: OrderingSchemaManager::GLOBAL_TABLE);
    }

    private function globalConfigWithoutSoftDeletes(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(table: OrderingSchemaManager::GLOBAL_TABLE, deletedAtColumn: null);
    }

    private function scopedConfig(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(table: OrderingSchemaManager::SCOPED_TABLE, scopeColumn: 'scope_key');
    }

    private function nullableScopedConfig(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(
            table: OrderingSchemaManager::SCOPED_TABLE,
            scopeColumn: 'scope_key',
            nullableScope: true,
        );
    }
}
