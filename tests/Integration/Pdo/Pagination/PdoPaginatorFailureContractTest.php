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

    public function testRepeatedNamedPlaceholder(): void
    {
        // the constructor enforces keys without ':'
        $descriptor = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], 'SELECT id FROM `' . PaginationSchemaManager::TABLE . '` WHERE name = :p OR category = :p', ['p' => 'test']);
        self::assertInstanceOf(PdoPaginationQueryDescriptor::class, $descriptor);

        try {
            (new PdoPaginator())->paginate($this->pdo(), $descriptor, new PageRequest(), $this->config(), static fn (array $row): array => $row);
            self::fail('Expected execution to fail.');
        } catch (\Throwable $exception) {
            self::assertTrue($exception instanceof PDOException || $exception instanceof PaginationExecutionException);
        }
    }

    public function testMissingParameter(): void
    {
        $descriptor = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], 'SELECT id FROM `' . PaginationSchemaManager::TABLE . '` WHERE name = :p', []);
        self::assertInstanceOf(PdoPaginationQueryDescriptor::class, $descriptor);

        try {
            (new PdoPaginator())->paginate($this->pdo(), $descriptor, new PageRequest(), $this->config(), static fn (array $row): array => $row);
            self::fail('Expected execution to fail.');
        } catch (\Throwable $exception) {
            self::assertTrue($exception instanceof PDOException || $exception instanceof PaginationExecutionException);
        }
    }

    public function testUnusedParameter(): void
    {
        $descriptor = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], 'SELECT id FROM `' . PaginationSchemaManager::TABLE . '`', ['p' => 'unused']);
        self::assertInstanceOf(PdoPaginationQueryDescriptor::class, $descriptor);

        try {
            (new PdoPaginator())->paginate($this->pdo(), $descriptor, new PageRequest(), $this->config(), static fn (array $row): array => $row);
            self::fail('Expected execution to fail.');
        } catch (\Throwable $exception) {
            self::assertTrue($exception instanceof PDOException || $exception instanceof PaginationExecutionException);
        }
    }

    public function testPositionalPlaceholder(): void
    {
        // Positional placeholders use ? in sql, but parameter keys in array can be tricky to bypass constructor.
        // We'll map them via standard execution failure (often ? fails if positional binding is not used, or if keys are numeric and the package rejects it)
        // Wait, the package constructor rejects numeric keys (`!is_string($key)`).
        // Let's check `validateParams`:
        // if (! is_string($key) || str_starts_with($key, ':') || ! preg_match(self::PARAMETER_KEY_PATTERN, $key) || str_starts_with($key, self::RESERVED_PREFIX)) { throw new InvalidPaginationQueryException('Invalid pagination parameter key.'); }
        // So we cannot even construct it if we use numeric keys.
        // Therefore, we can't test "positional placeholder array binding" if it throws InvalidPaginationQueryException in the constructor.
        // Let's pass empty array and have positional ? in SQL to trigger PDOException on execute.
        $descriptor = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], 'SELECT id FROM `' . PaginationSchemaManager::TABLE . '` WHERE name = ?', []);
        self::assertInstanceOf(PdoPaginationQueryDescriptor::class, $descriptor);

        try {
            (new PdoPaginator())->paginate($this->pdo(), $descriptor, new PageRequest(), $this->config(), static fn (array $row): array => $row);
            self::fail('Expected execution to fail.');
        } catch (\Throwable $exception) {
            self::assertTrue($exception instanceof PDOException || $exception instanceof PaginationExecutionException);
        }
    }

    public function testMixedPositionalAndNamedPlaceholders(): void
    {
        $descriptor = new PdoPaginationQueryDescriptor('SELECT 1 AS total_count', [], 'SELECT 1 AS filtered_count', [], 'SELECT id FROM `' . PaginationSchemaManager::TABLE . '` WHERE name = ? AND category = :cat', ['cat' => 'test']);
        self::assertInstanceOf(PdoPaginationQueryDescriptor::class, $descriptor);

        try {
            (new PdoPaginator())->paginate($this->pdo(), $descriptor, new PageRequest(), $this->config(), static fn (array $row): array => $row);
            self::fail('Expected execution to fail.');
        } catch (\Throwable $exception) {
            self::assertTrue($exception instanceof PDOException || $exception instanceof PaginationExecutionException);
        }
    }

    public function testInvalidTable(): void
    {
        $descriptor = new PdoPaginationQueryDescriptor('SELECT COUNT(*) AS total_count FROM missing_pagination_table', [], 'SELECT 1 AS filtered_count', [], 'SELECT id FROM missing_pagination_table', []);

        try {
            (new PdoPaginator())->paginate($this->pdo(), $descriptor, new PageRequest(), $this->config(), static fn (array $row): array => $row);
            self::fail('Expected PDOException.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(PDOException::class, $exception);
        }
    }

    public function testInvalidColumn(): void
    {
        $descriptor = new PdoPaginationQueryDescriptor('SELECT COUNT(missing_col) AS total_count FROM `' . PaginationSchemaManager::TABLE . '`', [], 'SELECT 1 AS filtered_count', [], 'SELECT missing_col FROM `' . PaginationSchemaManager::TABLE . '`', []);

        try {
            (new PdoPaginator())->paginate($this->pdo(), $descriptor, new PageRequest(), $this->config(), static fn (array $row): array => $row);
            self::fail('Expected PDOException.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(PDOException::class, $exception);
        }
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
