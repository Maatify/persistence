<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Pdo\Ordering;

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Tests\Support\MySql\MySqlConnectionFactory;
use Maatify\Persistence\Tests\Support\MySql\MySqlIntegrationTestCase;
use Maatify\Persistence\Tests\Support\MySql\OrderingFailureInjector;
use Maatify\Persistence\Tests\Support\MySql\OrderingSchemaManager;
use Maatify\Persistence\Tests\Support\MySql\OrderingStateReader;
use PDO;
use PDOException;
use Throwable;

final class ScopedOrderingManagerRollbackTest extends MySqlIntegrationTestCase
{
    private ScopedOrderingManager $manager;
    private PDO $controlPdo;
    private OrderingStateReader $primaryReader;
    private OrderingStateReader $controlReader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new ScopedOrderingManager();
        $this->controlPdo = (new MySqlConnectionFactory())->create();
        $this->primaryReader = new OrderingStateReader($this->pdo());
        $this->controlReader = new OrderingStateReader($this->controlPdo);
    }

    public function testMissingTableFailureRethrowsPdoExceptionAndClosesTransactionWithoutChangingRows(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3, 4);
        $before = $this->primaryReader->globalOrdersById();

        $thrown = $this->captureMoveFailure(
            new ScopedOrderingConfig(table: 'maa_persistence_test_missing_ordering'),
            null,
            $ids[0],
            2
        );

        self::assertInstanceOf(PDOException::class, $thrown);
        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame($before, $this->controlReader->globalOrdersById());
    }

    public function testGlobalUpwardMoveFailureAfterRangeShiftRollsBackAllRows(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3, 4);
        $before = $this->primaryReader->globalOrdersById();
        $failureInjector = new OrderingFailureInjector($this->controlPdo);

        try {
            $failureInjector->createGlobalTargetUpdateFailure($ids[3]);

            $thrown = $this->captureMoveFailure($this->globalConfig(), null, $ids[3], 2);

            self::assertInstanceOf(PDOException::class, $thrown);
            self::assertFalse($this->pdo()->inTransaction());
            self::assertSame([$ids[0] => 1, $ids[1] => 2, $ids[2] => 3, $ids[3] => 4], $before);
            self::assertSame($before, $this->controlReader->globalOrdersById());
        } finally {
            $failureInjector->dropAll();
        }
    }

    public function testGlobalDownwardMoveFailureAfterRangeShiftRollsBackAllRows(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3, 4);
        $before = $this->primaryReader->globalOrdersById();
        $failureInjector = new OrderingFailureInjector($this->controlPdo);

        try {
            $failureInjector->createGlobalTargetUpdateFailure($ids[0]);

            $thrown = $this->captureMoveFailure($this->globalConfig(), null, $ids[0], 4);

            self::assertInstanceOf(PDOException::class, $thrown);
            self::assertFalse($this->pdo()->inTransaction());
            self::assertSame([$ids[0] => 1, $ids[1] => 2, $ids[2] => 3, $ids[3] => 4], $before);
            self::assertSame($before, $this->controlReader->globalOrdersById());
        } finally {
            $failureInjector->dropAll();
        }
    }

    public function testScopedMoveFailureAfterRangeShiftRollsBackScopeAndLeavesUnrelatedScopeUnchanged(): void
    {
        $scopeAIds = $this->insertScopedOrders('scope-a', 1, 2, 3, 4);
        $scopeBIds = $this->insertScopedOrders('scope-b', 10, 20);
        $scopeABefore = $this->primaryReader->scopedOrdersById('scope-a');
        $scopeBBefore = $this->primaryReader->scopedOrdersById('scope-b');
        $failureInjector = new OrderingFailureInjector($this->controlPdo);

        try {
            $failureInjector->createScopedTargetUpdateFailure($scopeAIds[3]);

            $thrown = $this->captureMoveFailure($this->scopedConfig(), 'scope-a', $scopeAIds[3], 2);

            self::assertInstanceOf(PDOException::class, $thrown);
            self::assertFalse($this->pdo()->inTransaction());
            self::assertSame([$scopeAIds[0] => 1, $scopeAIds[1] => 2, $scopeAIds[2] => 3, $scopeAIds[3] => 4], $scopeABefore);
            self::assertSame([$scopeBIds[0] => 10, $scopeBIds[1] => 20], $scopeBBefore);
            self::assertSame($scopeABefore, $this->controlReader->scopedOrdersById('scope-a'));
            self::assertSame($scopeBBefore, $this->controlReader->scopedOrdersById('scope-b'));
        } finally {
            $failureInjector->dropAll();
        }
    }

    public function testConfiguredTimestampUpdateRollsBackWithTheOrderingMutation(): void
    {
        $target = $this->fixture->insertScoped('scope-a', 1, 'target', null, '2026-01-01 00:00:00');
        $other = $this->fixture->insertScoped('scope-a', 2, 'other', null, '2026-01-01 00:00:00');
        $before = $this->primaryReader->scopedOrdersById('scope-a');
        $failureInjector = new OrderingFailureInjector($this->controlPdo);

        try {
            $failureInjector->createScopedTargetUpdateFailure($other);

            $thrown = $this->captureMoveFailure(
                new ScopedOrderingConfig(
                    table: OrderingSchemaManager::SCOPED_TABLE,
                    scopeColumn: 'scope_key',
                    updatedAtColumn: 'updated_at',
                ),
                'scope-a',
                $other,
                1,
                '2026-01-03 12:34:56',
            );

            self::assertInstanceOf(PDOException::class, $thrown);
            self::assertFalse($this->pdo()->inTransaction());
            self::assertSame($before, $this->controlReader->scopedOrdersById('scope-a'));

            $timestamp = $this->controlPdo->query(
                'SELECT `updated_at` FROM `' . OrderingSchemaManager::SCOPED_TABLE . '` WHERE `id` = ' . $other,
            );
            self::assertNotFalse($timestamp);
            self::assertSame('2026-01-01 00:00:00', $timestamp->fetchColumn());
        } finally {
            $failureInjector->dropAll();
        }
    }

    private function captureMoveFailure(
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
        int $newOrder,
        ?string $updatedAtValue = null,
    ): Throwable {
        try {
            $this->manager->moveWithinScope(
                $this->pdo(),
                $config,
                $scopeValue,
                $id,
                $newOrder,
                $updatedAtValue,
            );
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('Expected moveWithinScope() to throw.');
    }

    /**
     * @return list<int>
     */
    private function insertGlobalOrders(int ...$orders): array
    {
        $ids = [];
        foreach ($orders as $order) {
            $ids[] = $this->fixture->insertGlobal($order);
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function insertScopedOrders(int|string|null $scopeValue, int ...$orders): array
    {
        $ids = [];
        foreach ($orders as $order) {
            $ids[] = $this->fixture->insertScoped($scopeValue, $order);
        }

        return $ids;
    }

    private function globalConfig(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(table: OrderingSchemaManager::GLOBAL_TABLE);
    }

    private function scopedConfig(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(table: OrderingSchemaManager::SCOPED_TABLE, scopeColumn: 'scope_key');
    }
}
