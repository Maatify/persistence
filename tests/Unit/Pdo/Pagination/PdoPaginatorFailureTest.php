<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Unit\Pdo\Pagination;

use Maatify\Persistence\Exception\PaginationExecutionException;
use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PaginationConfig;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Pdo\Pagination\SortDirectionEnum;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist;
use Maatify\Persistence\Tests\Support\Pdo\Pagination\ScriptedPdo;
use Maatify\Persistence\Tests\Support\Pdo\Pagination\ScriptedPdoStatement;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class PdoPaginatorFailureTest extends TestCase
{
    public function testTotalPrepareFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([false]), 1);
    }
    public function testFilteredPrepareFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), false]), 2);
    }
    public function testDataPrepareFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), false]), 3);
    }
    public function testTotalBindFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([$this->countStatement(bindResults: [':p' => false])]), 1);
    }
    public function testFilteredBindFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), $this->countStatement(bindResults: [':p' => false])]), 2);
    }
    public function testDataBindFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), $this->dataStatement(bindResults: [':p' => false])]), 3);
    }
    public function testInternalLimitBindFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), $this->dataStatement(bindResults: [':__pagination_limit' => false])]), 3);
    }
    public function testInternalOffsetBindFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), $this->dataStatement(bindResults: [':__pagination_offset' => false])]), 3);
    }
    public function testTotalExecuteFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([$this->countStatement(executeResult: false)]), 1);
    }
    public function testFilteredExecuteFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), $this->countStatement(executeResult: false)]), 2);
    }
    public function testDataExecuteFalse(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), $this->dataStatement(executeResult: false)]), 3);
    }

    public function testNormalEofProducesResult(): void
    {
        $data = ScriptedPdoStatement::data([['id' => 1]]);
        $result = (new PdoPaginator())->paginate(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), $data]), $this->queryWithoutParams(), new PageRequest(), $this->config(), static fn (array $row): array => $row);
        self::assertSame([['id' => 1]], $result->data);
        self::assertSame(2, $data->fetchCalls);
    }

    public function testFetchFalseWithNonSuccessSqlstate(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([$this->countStatement(fetchQueue: [false], errorCode: 'HY000')]), 1);
    }
    public function testInvalidScalarFetch(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([new ScriptedPdoStatement(1, [123], true, '00000')]), 1);
    }
    public function testNumericKeyRow(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([new ScriptedPdoStatement(1, [[1], false])]), 1);
    }
    public function testEmptyDataRow(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), new ScriptedPdoStatement(1, [[], false])]), 3);
    }
    public function testCountZeroRows(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([new ScriptedPdoStatement(1, [false])]), 1);
    }
    public function testCountMultipleRows(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([new ScriptedPdoStatement(1, [['total_count' => 1], ['total_count' => 2], false])]), 1);
    }
    public function testCountMultipleColumns(): void
    {
        $this->assertExecutionFailure(new ScriptedPdo([new ScriptedPdoStatement(2, [['a' => 1, 'b' => 2], false])]), 1);
    }

    public function testInvalidCountRepresentations(): void
    {
        foreach ([false, null, '', '-1', '+1', '1.2', '1e2', PHP_INT_MAX . '0', []] as $value) {
            $this->assertExecutionFailure(new ScriptedPdo([new ScriptedPdoStatement(1, [['total_count' => $value], false])]), 1);
        }
    }

    public function testDataFetchFailureAfterMappedRowDoesNotReturnPartialResult(): void
    {
        $data = new ScriptedPdoStatement(1, [['id' => 1], false], true, 'HY000');
        $this->assertExecutionFailure(new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), $data]), 3);
        self::assertSame(2, $data->fetchCalls);
    }

    public function testPdoExceptionIdentityFromPrepareBindExecuteAndFetch(): void
    {
        foreach ([$this->pdoPrepareThrowable(), $this->pdoBindThrowable(), $this->pdoExecuteThrowable(), $this->pdoFetchThrowable()] as [$pdo, $exception]) {
            try {
                (new PdoPaginator())->paginate($pdo, $this->query(), new PageRequest(), $this->config(), static fn (array $row): array => $row);
                self::fail('Expected PDOException.');
            } catch (PDOException $caught) {
                self::assertSame($exception, $caught);
            }
        }
    }

    public function testMapperThrowableIdentityAndInvalidMapperResults(): void
    {
        $exception = new RuntimeException('mapper');
        try {
            (new PdoPaginator())->paginate($this->successfulPdo(), $this->queryWithoutParams(), new PageRequest(), $this->config(), static fn (array $row) => throw $exception);
            self::fail('Expected mapper exception.');
        } catch (RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }

        $this->expectException(PaginationExecutionException::class);
        $this->invokePaginatorWithMapper(static fn (array $row): int => 1);
    }

    public function testResourceMapperResultIsRejectedAndClosed(): void
    {
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);
        try {
            $this->expectException(PaginationExecutionException::class);
            $this->invokePaginatorWithMapper(static fn (array $row) => $resource);
        } finally {
            fclose($resource);
        }
    }

    private function assertExecutionFailure(ScriptedPdo $pdo, int $prepareCount): void
    {
        try {
            (new PdoPaginator())->paginate($pdo, $this->query(), new PageRequest(), $this->config(), static fn (array $row): array => $row);
            self::fail('Expected PaginationExecutionException.');
        } catch (PaginationExecutionException $exception) {
            self::assertInstanceOf(PaginationExecutionException::class, $exception);
            self::assertCount($prepareCount, $pdo->preparedSql);
        }
    }

    private function invokePaginatorWithMapper(callable $mapper): void
    {
        (new ReflectionMethod(PdoPaginator::class, 'paginate'))->invokeArgs(new PdoPaginator(), [$this->successfulPdo(), $this->queryWithoutParams(), new PageRequest(), $this->config(), $mapper]);
    }

    private function query(): PdoPaginationQueryDescriptor
    {
        return new PdoPaginationQueryDescriptor('T', ['p' => 1], 'F', ['p' => 1], 'D', ['p' => 1]);
    }
    private function queryWithoutParams(): PdoPaginationQueryDescriptor
    {
        return new PdoPaginationQueryDescriptor('T', [], 'F', [], 'D', []);
    }
    private function config(): PaginationConfig
    {
        return new PaginationConfig(new SortWhitelist(['id' => 'id']), 'id', SortDirectionEnum::ASC, 'id', SortDirectionEnum::ASC);
    }
    private function successfulPdo(): ScriptedPdo
    {
        return new ScriptedPdo([
            ScriptedPdoStatement::count(1),
            ScriptedPdoStatement::count(1),
            ScriptedPdoStatement::data([['id' => 1]]),
        ]);
    }
    /**
     * @param list<mixed> $fetchQueue
     * @param array<string, bool|PDOException> $bindResults
     */
    private function countStatement(
        array $fetchQueue = [['total_count' => 1], false],
        bool|PDOException $executeResult = true,
        ?string $errorCode = '00000',
        array $bindResults = [],
    ): ScriptedPdoStatement {
        return new ScriptedPdoStatement(1, $fetchQueue, $executeResult, $errorCode, $bindResults);
    }
    /** @param array<string, bool|PDOException> $bindResults */
    private function dataStatement(bool|PDOException $executeResult = true, array $bindResults = []): ScriptedPdoStatement
    {
        return new ScriptedPdoStatement(1, [['id' => 1], false], $executeResult, '00000', $bindResults);
    }
    /** @return array{ScriptedPdo, PDOException} */
    private function pdoPrepareThrowable(): array
    {
        $exception = new PDOException('prepare');

        return [new ScriptedPdo([$exception]), $exception];
    }
    /** @return array{ScriptedPdo, PDOException} */
    private function pdoBindThrowable(): array
    {
        $exception = new PDOException('bind');

        return [new ScriptedPdo([$this->countStatement(bindResults: [':p' => $exception])]), $exception];
    }
    /** @return array{ScriptedPdo, PDOException} */
    private function pdoExecuteThrowable(): array
    {
        $exception = new PDOException('execute');

        return [new ScriptedPdo([$this->countStatement(executeResult: $exception)]), $exception];
    }
    /** @return array{ScriptedPdo, PDOException} */
    private function pdoFetchThrowable(): array
    {
        $exception = new PDOException('fetch');

        return [new ScriptedPdo([new ScriptedPdoStatement(1, [$exception])]), $exception];
    }
}
