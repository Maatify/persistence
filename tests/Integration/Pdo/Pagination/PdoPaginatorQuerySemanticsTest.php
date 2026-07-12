<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Pdo\Pagination;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Tests\Support\MySql\PaginationIntegrationTestCase;
use Maatify\Persistence\Tests\Support\MySql\PaginationSchemaManager;
use stdClass;

final class PdoPaginatorQuerySemanticsTest extends PaginationIntegrationTestCase
{
    public function testBaseTotalFilteredSortingTieBreakerAndConsecutivePages(): void
    {
        $ids = $this->insertMultiTenantDataset();
        $paginator = new PdoPaginator();

        $firstPage = $paginator->paginate(
            $this->pdo(),
            $this->descriptor(),
            new PageRequest(1, 2, 'score', ' asc '),
            $this->config(),
            static fn (array $row): array => $row,
        );
        $secondPage = $paginator->paginate(
            $this->pdo(),
            $this->descriptor(),
            new PageRequest(2, 2, 'score', 'ASC'),
            $this->config(),
            static fn (array $row): array => $row,
        );

        self::assertSame(4, $firstPage->total);
        self::assertSame(3, $firstPage->filtered);
        self::assertSame(2, $firstPage->totalPages);
        self::assertSame([$ids[0], $ids[1]], array_map('intval', array_column($firstPage->data, 'id')));
        self::assertSame([$ids[2]], array_map('intval', array_column($secondPage->data, 'id')));
    }

    public function testNoFilterIdenticalCountsNullBindingInvalidSortFallbackAndObjectMapper(): void
    {
        $ids = $this->insertMultiTenantDataset();
        $table = PaginationSchemaManager::TABLE;
        $descriptor = new PdoPaginationQueryDescriptor(
            "SELECT COUNT(*) AS total_count FROM `$table` WHERE tenant_id = :tenant AND deleted_at IS NULL AND nullable_code <=> :code",
            ['tenant' => 1, 'code' => null],
            "SELECT COUNT(*) AS filtered_count FROM `$table` WHERE tenant_id = :tenant_filtered AND deleted_at IS NULL AND nullable_code <=> :code_filtered",
            ['tenant_filtered' => 1, 'code_filtered' => null],
            "SELECT id, name FROM `$table` WHERE tenant_id = :tenant_data AND deleted_at IS NULL AND nullable_code <=> :code_data",
            ['tenant_data' => 1, 'code_data' => null],
        );
        $objects = [];

        $result = (new PdoPaginator())->paginate(
            $this->pdo(),
            $descriptor,
            new PageRequest(1, 10, 'unknown', 'bad'),
            $this->config(),
            static function (array $row) use (&$objects): stdClass {
                $object = (object) $row;
                $objects[] = $object;

                return $object;
            },
        );

        self::assertSame(2, $result->total);
        self::assertSame($result->total, $result->filtered);
        self::assertSame('created_at', $result->sortBy);
        self::assertSame('DESC', $result->sortDirection->value);
        self::assertSame([$ids[3], $ids[0]], array_map(static fn (object $row): int => (int) $row->id, $result->data));
        self::assertSame($objects[0], $result->data[0]);
    }

    public function testOverflowZeroFilteredEmptyPositiveFilteredAndFilteredGreaterThanTotal(): void
    {
        $this->insertMultiTenantDataset();
        $table = PaginationSchemaManager::TABLE;
        $paginator = new PdoPaginator();

        $overflow = $paginator->paginate(
            $this->pdo(),
            $this->descriptor(),
            new PageRequest(99, 2),
            $this->config(),
            static fn (array $row): array => $row,
        );
        self::assertSame(1, $overflow->page);
        self::assertCount(2, $overflow->data);

        $zero = $paginator->paginate(
            $this->pdo(),
            new PdoPaginationQueryDescriptor(
                "SELECT COUNT(*) AS total_count FROM `$table` WHERE tenant_id = 1",
                [],
                "SELECT COUNT(*) AS filtered_count FROM `$table` WHERE tenant_id = 999",
                [],
                'THIS INVALID DATA SQL MUST NOT BE PREPARED',
                [],
            ),
            new PageRequest(7, 2),
            $this->config(),
            static fn (array $row): array => $row,
        );
        self::assertSame(1, $zero->page);
        self::assertSame([], $zero->data);
        self::assertSame(0, $zero->filtered);

        $emptyPositive = $paginator->paginate(
            $this->pdo(),
            new PdoPaginationQueryDescriptor(
                "SELECT COUNT(*) AS total_count FROM `$table` WHERE tenant_id = 1",
                [],
                'SELECT 1 AS filtered_count',
                [],
                "SELECT id FROM `$table` WHERE tenant_id = 999",
                [],
            ),
            new PageRequest(1, 2),
            $this->config(),
            static fn (array $row): array => $row,
        );
        self::assertSame(1, $emptyPositive->filtered);
        self::assertSame([], $emptyPositive->data);
        self::assertSame(1, $emptyPositive->totalPages);

        $filteredGreaterThanTotal = $paginator->paginate(
            $this->pdo(),
            new PdoPaginationQueryDescriptor(
                'SELECT 1 AS total_count',
                [],
                'SELECT 3 AS filtered_count',
                [],
                "SELECT id FROM `$table` WHERE tenant_id = 1 AND deleted_at IS NULL AND is_active = 1",
                [],
            ),
            new PageRequest(1, 3),
            $this->config(),
            static fn (array $row): array => $row,
        );
        self::assertSame(1, $filteredGreaterThanTotal->total);
        self::assertSame(3, $filteredGreaterThanTotal->filtered);
    }
}
