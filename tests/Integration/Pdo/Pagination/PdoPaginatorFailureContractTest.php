<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Pdo\Pagination;

use Maatify\Persistence\Exception\PaginationExecutionException;
use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Tests\Support\MySql\PaginationIntegrationTestCase;
use Maatify\Persistence\Tests\Support\MySql\PaginationSchemaManager;
use PDOException;
use ReflectionMethod;
use RuntimeException;

final class PdoPaginatorFailureContractTest extends PaginationIntegrationTestCase
{
    public function testCountShapeFailures(): void
    {
        $this->insertMultiTenantDataset();
        $table = PaginationSchemaManager::TABLE;

        $descriptors = [
            new PdoPaginationQueryDescriptor("SELECT id AS total_count FROM `$table` WHERE tenant_id = 999", [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table`", []),
            new PdoPaginationQueryDescriptor("SELECT id AS total_count FROM `$table` WHERE tenant_id = 1", [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table`", []),
            new PdoPaginationQueryDescriptor("SELECT id, name FROM `$table` WHERE tenant_id = 1 LIMIT 1", [], 'SELECT 1 AS filtered_count', [], "SELECT id FROM `$table`", []),
        ];

        foreach ($descriptors as $descriptor) {
            try {
                (new PdoPaginator())->paginate($this->pdo(), $descriptor, new PageRequest(), $this->config(), static fn (array $row): array => $row);
                self::fail('Expected count shape failure.');
            } catch (PaginationExecutionException $exception) {
                self::assertInstanceOf(PaginationExecutionException::class, $exception);
            }
        }
    }

    public function testPlaceholderViolationsAndInvalidTableColumnPropagatePdoException(): void
    {
        $this->expectException(PDOException::class);

        (new PdoPaginator())->paginate(
            $this->pdo(),
            new PdoPaginationQueryDescriptor('SELECT COUNT(*) AS total_count FROM missing_pagination_table', [], 'SELECT 1 AS filtered_count', [], 'SELECT id FROM missing_pagination_table', []),
            new PageRequest(),
            $this->config(),
            static fn (array $row): array => $row,
        );
    }

    public function testMapperThrowableIdentityAndInvalidMapperResults(): void
    {
        $this->insertMultiTenantDataset();
        $throwable = new RuntimeException('mapper');

        try {
            (new PdoPaginator())->paginate($this->pdo(), $this->descriptor(), new PageRequest(), $this->config(), static fn (array $row) => throw $throwable);
            self::fail('Expected mapper throwable.');
        } catch (RuntimeException $caught) {
            self::assertSame($throwable, $caught);
        }

        $this->expectException(PaginationExecutionException::class);
        $this->invokePaginatorWithMapper(static fn (array $row): int => 1);
    }

    public function testResourceMapperFailureClosesResource(): void
    {
        $this->insertMultiTenantDataset();
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            $this->expectException(PaginationExecutionException::class);
            $this->invokePaginatorWithMapper(static fn (array $row) => $resource);
        } finally {
            fclose($resource);
        }
    }

    private function invokePaginatorWithMapper(callable $mapper): void
    {
        $method = new ReflectionMethod(PdoPaginator::class, 'paginate');
        $method->invokeArgs(new PdoPaginator(), [
            $this->pdo(),
            $this->descriptor(),
            new PageRequest(),
            $this->config(),
            $mapper,
        ]);
    }
}
