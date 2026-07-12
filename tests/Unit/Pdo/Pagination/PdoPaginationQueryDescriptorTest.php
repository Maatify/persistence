<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Unit\Pdo\Pagination;

use Maatify\Persistence\Exception\InvalidPaginationQueryException;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PdoPaginationQueryDescriptorTest extends TestCase
{
    public function testValidDescriptorPreservesSqlAndMaps(): void
    {
        $descriptor = new PdoPaginationQueryDescriptor(
            ' SELECT COUNT(*) AS total_count ',
            ['a' => 1],
            'SELECT COUNT(*) AS filtered_count',
            ['b' => '1.23'],
            'SELECT id FROM t',
            ['c' => true, 'd' => null],
        );

        self::assertSame(' SELECT COUNT(*) AS total_count ', $descriptor->totalSql);
        self::assertSame(['a' => 1], $descriptor->totalParams);
        self::assertSame(['b' => '1.23'], $descriptor->filteredCountParams);
        self::assertSame(['c' => true, 'd' => null], $descriptor->dataParams);
    }

    #[DataProvider('badSql')]
    public function testSqlRejection(string $sql): void
    {
        $this->expectException(InvalidPaginationQueryException::class);

        new PdoPaginationQueryDescriptor($sql, [], 'SELECT COUNT(*) AS filtered_count', [], 'SELECT id FROM t', []);
    }

    /** @return iterable<string, array{string}> */
    public static function badSql(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'leading semicolon' => [';SELECT 1'];
        yield 'middle semicolon' => ['SELECT 1; SELECT 2'];
        yield 'trailing semicolon' => ['SELECT 1;'];
        yield 'reserved limit' => ['SELECT :__pagination_limit'];
        yield 'reserved offset' => ['SELECT :__pagination_offset'];
        yield 'reserved custom' => ['SELECT :__pagination_custom'];
    }

    /** @param array<mixed, mixed> $params */
    #[DataProvider('badParams')]
    public function testTotalParamRejection(array $params): void
    {
        $this->expectException(InvalidPaginationQueryException::class);

        $this->newDescriptor('SELECT COUNT(*) AS total_count', $params, 'SELECT COUNT(*) AS filtered_count', [], 'SELECT id FROM t', []);
    }

    /** @param array<mixed, mixed> $params */
    #[DataProvider('badParams')]
    public function testFilteredParamRejection(array $params): void
    {
        $this->expectException(InvalidPaginationQueryException::class);

        $this->newDescriptor('SELECT COUNT(*) AS total_count', [], 'SELECT COUNT(*) AS filtered_count', $params, 'SELECT id FROM t', []);
    }

    /** @param array<mixed, mixed> $params */
    #[DataProvider('badParams')]
    public function testDataParamRejection(array $params): void
    {
        $this->expectException(InvalidPaginationQueryException::class);

        $this->newDescriptor('SELECT COUNT(*) AS total_count', [], 'SELECT COUNT(*) AS filtered_count', [], 'SELECT id FROM t', $params);
    }

    /** @return iterable<string, array{array<mixed, mixed>}> */
    public static function badParams(): iterable
    {
        yield 'integer key' => [[1 => 'x']];
        yield 'empty key' => [['' => 'x']];
        yield 'leading colon' => [[':a' => 'x']];
        yield 'starts digit' => [['1a' => 'x']];
        yield 'whitespace' => [['a b' => 'x']];
        yield 'hyphen' => [['a-b' => 'x']];
        yield 'reserved' => [['__pagination_limit' => 1]];
        yield 'float' => [['a' => 1.2]];
        yield 'array' => [['a' => []]];
        yield 'object' => [['a' => new \stdClass()]];
    }

    public function testResourceParamRejectionClosesResource(): void
    {
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            $this->expectException(InvalidPaginationQueryException::class);
            $this->newDescriptor('SELECT COUNT(*) AS total_count', ['a' => $resource], 'SELECT COUNT(*) AS filtered_count', [], 'SELECT id FROM t', []);
        } finally {
            fclose($resource);
        }
    }

    #[DataProvider('noParser')]
    public function testNoParserContractAllowsNonPreflightSql(string $sql): void
    {
        $descriptor = new PdoPaginationQueryDescriptor('SELECT COUNT(*) AS total_count', [], 'SELECT COUNT(*) AS filtered_count', [], $sql, []);

        self::assertSame($sql, $descriptor->dataSql);
    }

    /** @return iterable<string, array{string}> */
    public static function noParser(): iterable
    {
        yield 'missing placeholder' => ['SELECT :missing'];
        yield 'unused parameter possible' => ['SELECT id FROM t'];
        yield 'repeated named' => ['SELECT :x + :x'];
        yield 'positional' => ['SELECT ?'];
        yield 'mixed' => ['SELECT ? AND :x'];
        yield 'order by' => ['SELECT id FROM t ORDER BY id'];
        yield 'limit' => ['SELECT id FROM t LIMIT 1'];
        yield 'malformed' => ['MALFORMED SQL'];
    }

    /**
     * @param array<mixed, mixed> $totalParams
     * @param array<mixed, mixed> $filteredParams
     * @param array<mixed, mixed> $dataParams
     */
    private function newDescriptor(string $totalSql, array $totalParams, string $filteredSql, array $filteredParams, string $dataSql, array $dataParams): PdoPaginationQueryDescriptor
    {
        $reflection = new ReflectionClass(PdoPaginationQueryDescriptor::class);

        /** @var PdoPaginationQueryDescriptor $descriptor */
        $descriptor = $reflection->newInstanceArgs([$totalSql, $totalParams, $filteredSql, $filteredParams, $dataSql, $dataParams]);

        return $descriptor;
    }
}
