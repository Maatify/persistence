<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\MySql;

use Maatify\Persistence\Pdo\Pagination\PaginationConfig;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\SortDirectionEnum;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class PaginationIntegrationTestCase extends TestCase
{
    protected static PDO $pdo;
    protected static PaginationSchemaManager $schemaManager;

    protected PaginationFixture $fixture;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = (new MySqlConnectionFactory())->create();
        self::$schemaManager = new PaginationSchemaManager(self::$pdo);
        self::$schemaManager->initialize();
    }

    protected function setUp(): void
    {
        $this->rollBackOpenTransaction();
        self::$schemaManager->reset();
        $this->fixture = new PaginationFixture(self::$pdo);
    }

    protected function tearDown(): void
    {
        $this->rollBackOpenTransaction();
        self::$schemaManager->reset();
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$pdo) && self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }

        if (isset(self::$schemaManager)) {
            self::$schemaManager->dropTable();
        }
    }

    protected function pdo(): PDO
    {
        return self::$pdo;
    }

    protected function config(): PaginationConfig
    {
        return new PaginationConfig(
            new SortWhitelist([
                'id' => 'id',
                'score' => 'score',
                'created_at' => 'created_at',
                'same_id' => 'id',
            ]),
            'created_at',
            SortDirectionEnum::DESC,
            'id',
            SortDirectionEnum::ASC,
            2,
            1,
            50,
        );
    }

    /**
     * @param array<string, int|string|bool|null> $total
     * @param array<string, int|string|bool|null> $filtered
     * @param array<string, int|string|bool|null> $data
     */
    protected function descriptor(array $total = [], array $filtered = [], array $data = []): PdoPaginationQueryDescriptor
    {
        $table = PaginationSchemaManager::TABLE;

        return new PdoPaginationQueryDescriptor(
            "SELECT COUNT(*) AS total_count FROM `$table` WHERE tenant_id = :tenant_total AND deleted_at IS NULL",
            $total ?: ['tenant_total' => 1],
            "SELECT COUNT(*) AS filtered_count FROM `$table` WHERE tenant_id = :tenant_filtered AND deleted_at IS NULL AND is_active = :active_filtered",
            $filtered ?: ['tenant_filtered' => 1, 'active_filtered' => true],
            "SELECT id, tenant_id, category, name, score, is_active, nullable_code, created_at, deleted_at FROM `$table` WHERE tenant_id = :tenant_data AND deleted_at IS NULL AND is_active = :active_data",
            $data ?: ['tenant_data' => 1, 'active_data' => true],
        );
    }

    /** @return list<int> */
    protected function insertMultiTenantDataset(): array
    {
        return [
            $this->fixture->insertItem(1, 'book', 'Alpha', 10, true, null, '2026-01-01 00:00:00.000000'),
            $this->fixture->insertItem(1, 'book', 'Beta', 10, true, 'B', '2026-01-02 00:00:00.000000'),
            $this->fixture->insertItem(1, 'tool', 'Gamma', 20, true, 'C', '2026-01-03 00:00:00.000000'),
            $this->fixture->insertItem(1, 'book', 'Inactive', 30, false, null, '2026-01-04 00:00:00.000000'),
            $this->fixture->insertItem(1, 'book', 'Deleted', 40, true, null, '2026-01-05 00:00:00.000000', '2026-01-06 00:00:00.000000'),
            $this->fixture->insertItem(2, 'book', 'Other Tenant', 50, true, null, '2026-01-07 00:00:00.000000'),
        ];
    }

    private function rollBackOpenTransaction(): void
    {
        if (isset(self::$pdo) && self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
    }
}
