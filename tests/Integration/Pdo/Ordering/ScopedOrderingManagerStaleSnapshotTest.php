<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Pdo\Ordering;

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Tests\Support\MySql\MySqlConnectionFactory;
use Maatify\Persistence\Tests\Support\MySql\MySqlIntegrationTestCase;
use Maatify\Persistence\Tests\Support\MySql\OrderingFixture;
use Maatify\Persistence\Tests\Support\MySql\OrderingSchemaManager;
use Maatify\Persistence\Tests\Support\MySql\OrderingStateReader;
use PDO;
use PDOException;

/**
 * Regression protection for getNextPosition() inside a caller-owned transaction
 * whose REPEATABLE READ snapshot is older than another connection's commit.
 */
final class ScopedOrderingManagerStaleSnapshotTest extends MySqlIntegrationTestCase
{
    private const LOCK_WAIT_TIMEOUT_ERROR = 1205;

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

        self::assertSame(2, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));
    }

    public function testGlobalNextPositionSeesRowCommittedAfterCallerSnapshot(): void
    {
        $this->fixture->insertGlobal(1);
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertGlobal(2);
        $this->otherFixture->insertGlobal(3);

        self::assertSame(4, $this->manager->getNextPosition($this->pdo(), $this->globalConfig()));
    }

    public function testNullableScopeNextPositionSeesRowCommittedAfterCallerSnapshot(): void
    {
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped(null, 1);
        $this->otherFixture->insertScoped('scope-a', 7);

        self::assertSame(2, $this->manager->getNextPosition($this->pdo(), $this->nullableScopedConfig(), null));
    }

    public function testEmptyScopeStillReturnsOneInsideCallerTransaction(): void
    {
        $this->fixture->insertScoped('other-scope', 6);
        $this->beginTransactionWithSnapshot();

        self::assertSame(1, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));
        self::assertSame(1, $this->manager->getNextPosition($this->pdo(), $this->globalConfig()));
    }

    public function testNextPositionIgnoresSoftDeletedRowsCommittedAfterCallerSnapshot(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 2);
        $this->otherFixture->insertScoped('scope-a', 9, deletedAt: '2026-01-01 00:00:00');

        self::assertSame(3, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));
    }

    public function testNextPositionIncludesSoftDeletedRowsWhenFilteringIsDisabledInsideCallerTransaction(): void
    {
        $this->fixture->insertGlobal(2);
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertGlobal(9, deletedAt: '2026-01-01 00:00:00');

        self::assertSame(10, $this->manager->getNextPosition($this->pdo(), $this->globalConfigWithoutSoftDeletes()));
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

        self::assertSame(2, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));
    }

    public function testNextPositionStaysIndependentPerScopeInsideCallerTransaction(): void
    {
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 4);
        $this->otherFixture->insertScoped('scope-b', 8);

        self::assertSame(5, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));
        self::assertSame(9, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-b'));
    }

    public function testStaleSnapshotInsertAndMoveKeepLivePositionsUniqueAndContiguous(): void
    {
        $this->beginTransactionWithSnapshot();
        $this->otherFixture->insertScoped('scope-a', 1);

        $position = $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a');
        $newId = $this->fixture->insertScoped('scope-a', $position);
        $this->pdo()->commit();

        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->scopedConfig(), 'scope-a', $newId, 1));

        $orders = array_values($this->otherReader->scopedOrdersById('scope-a'));
        sort($orders);
        self::assertSame([1, 2], $orders);
    }

    public function testCallerTransactionOwnershipIsPreserved(): void
    {
        $this->fixture->insertGlobal(1);

        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame(2, $this->manager->getNextPosition($this->pdo(), $this->globalConfig()));
        self::assertFalse($this->pdo()->inTransaction(), 'Outside a transaction the manager must not begin one.');

        $this->pdo()->beginTransaction();
        self::assertSame(2, $this->manager->getNextPosition($this->pdo(), $this->globalConfig()));
        self::assertTrue($this->pdo()->inTransaction(), 'The manager must not commit a caller-owned transaction.');

        $this->pdo()->rollBack();
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testEmptyScopeIsNotSerializedBetweenConcurrentCallerTransactions(): void
    {
        // Documented limit: an empty scope has no row to lock, so two callers
        // both receive position 1 and the caller must serialize first inserts.
        $this->beginTransactionWithSnapshot();
        $this->otherPdo->beginTransaction();

        self::assertSame(1, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));
        self::assertSame(1, $this->manager->getNextPosition($this->otherPdo, $this->scopedConfig(), 'scope-a'));

        $this->otherPdo->rollBack();
    }

    public function testCallerTransactionHoldsLastRowLockUntilCallerEnds(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->fixture->insertScoped('scope-a', 2);
        $this->beginTransactionWithSnapshot();

        self::assertSame(3, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));

        // A concurrent allocator for the same non-empty scope is serialized.
        $this->otherPdo->beginTransaction();

        try {
            $this->manager->getNextPosition($this->otherPdo, $this->scopedConfig(), 'scope-a');
            self::fail('Concurrent allocation in a locked scope must wait for the caller transaction.');
        } catch (PDOException $exception) {
            self::assertSame(self::LOCK_WAIT_TIMEOUT_ERROR, $exception->errorInfo[1] ?? null);
        }

        $this->otherPdo->rollBack();

        // Once the caller ends its transaction the next allocator proceeds.
        $newId = $this->fixture->insertScoped('scope-a', 3);
        $this->pdo()->commit();

        $this->otherPdo->beginTransaction();
        self::assertSame(4, $this->manager->getNextPosition($this->otherPdo, $this->scopedConfig(), 'scope-a'));
        $this->otherPdo->rollBack();

        self::assertSame(3, $this->otherReader->scopedOrdersById('scope-a')[$newId]);
    }

    public function testCallerTransactionLockDoesNotBlockRowsOfOtherScopes(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->fixture->insertScoped('scope-a', 2);
        $otherId = $this->fixture->insertScoped('scope-0', 1);
        $this->beginTransactionWithSnapshot();

        $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a');

        $statement = $this->otherPdo->prepare(
            'UPDATE `' . OrderingSchemaManager::SCOPED_TABLE . '` SET `label` = :label WHERE `id` = :id'
        );
        self::assertNotFalse($statement);
        $statement->execute(['label' => 'updated outside locked scope', 'id' => $otherId]);

        self::assertSame(1, $statement->rowCount());
    }

    public function testNextPositionOutsideTransactionTakesNoLock(): void
    {
        $this->fixture->insertScoped('scope-a', 1);
        $this->fixture->insertScoped('scope-a', 2);

        $this->otherPdo->beginTransaction();
        $this->otherPdo->query(
            'SELECT `id` FROM `' . OrderingSchemaManager::SCOPED_TABLE . '`
              WHERE `scope_key` = \'scope-a\' ORDER BY `display_order` DESC FOR UPDATE'
        );

        // Would hit the lock wait timeout if this call used a locking read.
        self::assertSame(3, $this->manager->getNextPosition($this->pdo(), $this->scopedConfig(), 'scope-a'));
        self::assertFalse($this->pdo()->inTransaction());

        $this->otherPdo->rollBack();
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
