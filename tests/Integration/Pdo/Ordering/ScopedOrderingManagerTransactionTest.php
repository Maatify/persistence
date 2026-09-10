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
use RuntimeException;
use Throwable;

final class ScopedOrderingManagerTransactionTest extends MySqlIntegrationTestCase
{
    private ScopedOrderingManager $manager;
    private OrderingStateReader $reader;
    private PDO $controlPdo;
    private OrderingStateReader $controlReader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new ScopedOrderingManager();
        $this->reader = new OrderingStateReader($this->pdo());
        $this->controlPdo = (new MySqlConnectionFactory())->create();
        $this->controlReader = new OrderingStateReader($this->controlPdo);
    }

    public function testParticipatesInCallerOwnedTransactionAndLeavesItActive(): void
    {
        $first = $this->fixture->insertGlobal(1);
        $second = $this->fixture->insertGlobal(2);
        $before = $this->reader->globalOrdersById();

        $this->pdo()->beginTransaction();

        try {
            self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $first, 2));
            self::assertTrue($this->pdo()->inTransaction());
            self::assertSame([$first => 2, $second => 1], $this->reader->globalOrdersById());
        } finally {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
        }

        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame($before, $this->reader->globalOrdersById());
    }

    public function testOuterRollbackReversesOrderingMutation(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3);
        $before = $this->reader->globalOrdersById();

        $this->pdo()->beginTransaction();
        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[0], 3));
        self::assertTrue($this->pdo()->inTransaction());
        self::assertNotSame($before, $this->reader->globalOrdersById());

        $this->pdo()->rollBack();

        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame($before, $this->reader->globalOrdersById());
    }

    public function testOuterCommitPersistsOrderingMutation(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3);

        $this->pdo()->beginTransaction();
        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[0], 3));
        self::assertTrue($this->pdo()->inTransaction());
        $this->pdo()->commit();

        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame([$ids[0] => 3, $ids[1] => 1, $ids[2] => 2], $this->controlReader->globalOrdersById());
    }

    public function testMultipleOrderingOperationsParticipateInOneCallerTransaction(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3, 4);

        $this->pdo()->beginTransaction();
        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[3], 1));
        self::assertTrue($this->pdo()->inTransaction());
        self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[3], 3));
        self::assertTrue($this->pdo()->inTransaction());
        $this->pdo()->commit();

        self::assertSame([$ids[0] => 1, $ids[1] => 2, $ids[2] => 4, $ids[3] => 3], $this->controlReader->globalOrdersById());
    }

    public function testParticipantFailureLeavesOuterTransactionActiveWithoutIndependentRollback(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3, 4);
        $before = $this->reader->globalOrdersById();
        $failureInjector = new OrderingFailureInjector($this->controlPdo);

        try {
            $failureInjector->createGlobalTargetUpdateFailure($ids[3]);
            $this->pdo()->beginTransaction();

            try {
                $this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[3], 2);
                self::fail('Expected the ordering PDO exception.');
            } catch (PDOException $throwable) {
                self::assertSame('45000', $throwable->getCode());
                self::assertTrue($this->pdo()->inTransaction());
                self::assertSame([$ids[0] => 1, $ids[1] => 3, $ids[2] => 4, $ids[3] => 4], $this->reader->globalOrdersById());
            }

            $this->pdo()->rollBack();
            self::assertSame($before, $this->controlReader->globalOrdersById());
        } finally {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            $failureInjector->dropAll();
        }
    }

    public function testOrdinaryPdoMutationAndOrderingMutationRollbackAtomically(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3);
        $before = $this->reader->globalOrdersById();
        $failure = new RuntimeException('outer transaction failure.');

        try {
            $this->pdo()->beginTransaction();
            $statement = $this->pdo()->prepare(
                'UPDATE `' . OrderingSchemaManager::GLOBAL_TABLE . '` SET `label` = :label WHERE `id` = :id'
            );
            self::assertNotFalse($statement);
            $statement->execute(['label' => 'changed in outer transaction', 'id' => $ids[0]]);
            self::assertTrue($this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[0], 3));

            throw $failure;
        } catch (Throwable $throwable) {
            self::assertSame($failure, $throwable);
            self::assertTrue($this->pdo()->inTransaction());
            $this->pdo()->rollBack();
        }

        self::assertSame($before, $this->reader->globalOrdersById());
        $labelStatement = $this->pdo()->prepare(
            'SELECT `label` FROM `' . OrderingSchemaManager::GLOBAL_TABLE . '` WHERE `id` = :id'
        );
        self::assertNotFalse($labelStatement);
        $labelStatement->execute(['id' => $ids[0]]);
        self::assertSame('global row', $labelStatement->fetchColumn());
    }

    public function testSuccessfulMovementClosesOwnedTransaction(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3);

        $result = $this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[0], 3);

        self::assertTrue($result);
        self::assertSame([$ids[0] => 3, $ids[1] => 1, $ids[2] => 2], $this->reader->globalOrdersById());
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testMissingTargetClosesOwnedTransaction(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3);
        $before = $this->reader->globalOrdersById();
        $missingId = $ids[2] + 100;

        $result = $this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $missingId, 2);

        self::assertFalse($result);
        self::assertSame($before, $this->reader->globalOrdersById());
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testNoOpClosesOwnedTransaction(): void
    {
        $ids = $this->insertGlobalOrders(1, 2, 3);
        $before = $this->reader->globalOrdersById();

        $result = $this->manager->moveWithinScope($this->pdo(), $this->globalConfig(), null, $ids[2], 999);

        self::assertTrue($result);
        self::assertSame($before, $this->reader->globalOrdersById());
        self::assertFalse($this->pdo()->inTransaction());
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

    private function globalConfig(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(table: OrderingSchemaManager::GLOBAL_TABLE);
    }
}
