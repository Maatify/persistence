<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Regression\Exception;

use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\Persistence\Exception\InvalidPaginationConfigurationException;
use Maatify\Persistence\Exception\InvalidPaginationQueryException;
use Maatify\Persistence\Exception\PaginationExecutionException;
use Maatify\Persistence\Exception\PersistenceException;
use Maatify\Persistence\Pdo\Pagination\PageResult;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\SortDirectionEnum;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PaginationExceptionContractTest extends TestCase
{
    /** @param class-string<\Throwable> $className */
    #[DataProvider('exceptionClasses')]
    public function testExceptionContract(string $className): void
    {
        $class = new ReflectionClass($className);
        $exception = new $className('pagination');

        self::assertTrue($class->isFinal());
        self::assertInstanceOf(SystemMaatifyException::class, $exception);
        self::assertInstanceOf(PersistenceException::class, $exception);
        self::assertSame(ErrorCodeEnum::MAATIFY_ERROR, $exception->getErrorCode());
    }

    /** @return iterable<string, array{class-string<\Throwable>}> */
    public static function exceptionClasses(): iterable
    {
        yield 'configuration' => [InvalidPaginationConfigurationException::class];
        yield 'query' => [InvalidPaginationQueryException::class];
        yield 'execution' => [PaginationExecutionException::class];
    }

    public function testNoPaginationSpecificMarkerExists(): void
    {
        self::assertFalse(interface_exists('Maatify\\Persistence\\Exception\\PaginationException'));
    }

    public function testRepresentativeConfigurationTrigger(): void
    {
        $this->expectException(InvalidPaginationConfigurationException::class);

        $reflection = new ReflectionClass(SortWhitelist::class);
        $reflection->newInstanceArgs([[]]);
    }

    public function testRepresentativeQueryTrigger(): void
    {
        $this->expectException(InvalidPaginationQueryException::class);

        new PdoPaginationQueryDescriptor('', [], 'SELECT COUNT(*) AS filtered_count', [], 'SELECT id FROM t', []);
    }

    public function testRepresentativeExecutionTrigger(): void
    {
        $this->expectException(PaginationExecutionException::class);

        new PageResult([], 0, 20, 0, 0, 0, false, false, 'id', SortDirectionEnum::ASC);
    }
}
