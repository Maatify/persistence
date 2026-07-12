<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Regression\Pdo\Pagination;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PaginationConfig;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Pdo\Pagination\SortDirectionEnum;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist;
use Maatify\Persistence\Tests\Support\Pdo\Pagination\ScriptedPdo;
use Maatify\Persistence\Tests\Support\Pdo\Pagination\ScriptedPdoStatement;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaginationSqlContractRegressionTest extends TestCase
{
    public function testDistinctTieBreakerSqlExecutionOrderSeparateBindingsAndRawSortIsolation(): void
    {
        $total = ScriptedPdoStatement::count(10);
        $filtered = ScriptedPdoStatement::count(3);
        $data = ScriptedPdoStatement::data([['id' => 1]]);
        $pdo = new ScriptedPdo([$total, $filtered, $data]);
        $query = new PdoPaginationQueryDescriptor(
            'TOTAL SQL',
            ['total_tenant' => 1],
            'FILTERED SQL',
            ['filtered_category' => 'book'],
            'DATA SQL',
            ['data_active' => true],
        );

        $result = (new PdoPaginator())->paginate(
            $pdo,
            $query,
            new PageRequest(1, 2, ' created_at ', 'desc; DROP'),
            $this->config(),
            static fn (array $row): array => $row,
        );

        self::assertSame('created_at', $result->sortBy);
        self::assertSame('DESC', $result->sortDirection->value);
        self::assertSame([
            'TOTAL SQL',
            'FILTERED SQL',
            "DATA SQL\nORDER BY `created_at` DESC, `id` ASC\nLIMIT :__pagination_limit\nOFFSET :__pagination_offset",
        ], $pdo->preparedSql);
        self::assertSame([':total_tenant'], array_column($total->bindCalls, 'parameter'));
        self::assertSame([':filtered_category'], array_column($filtered->bindCalls, 'parameter'));
        self::assertSame([':data_active', ':__pagination_limit', ':__pagination_offset'], array_column($data->bindCalls, 'parameter'));
        self::assertSame([PDO::PARAM_BOOL, PDO::PARAM_INT, PDO::PARAM_INT], array_column($data->bindCalls, 'type'));
        self::assertStringNotContainsString('DROP', $pdo->preparedSql[2]);
    }

    public function testDuplicateIdentifierSqlZeroFilteredSkipAndOverflowSingleExecution(): void
    {
        $zeroPdo = new ScriptedPdo([ScriptedPdoStatement::count(10), ScriptedPdoStatement::count(0)]);
        $zero = (new PdoPaginator())->paginate(
            $zeroPdo,
            $this->descriptor(),
            new PageRequest(9, 2),
            $this->config(duplicate: true),
            static fn (array $row): array => $row,
        );
        self::assertSame([], $zero->data);
        self::assertSame(['TOTAL SQL', 'FILTERED SQL'], $zeroPdo->preparedSql);

        $data = ScriptedPdoStatement::data([]);
        $overflowPdo = new ScriptedPdo([ScriptedPdoStatement::count(10), ScriptedPdoStatement::count(3), $data]);
        $overflow = (new PdoPaginator())->paginate(
            $overflowPdo,
            $this->descriptor(),
            new PageRequest(99, 2),
            $this->config(duplicate: true),
            static fn (array $row): array => $row,
        );

        self::assertSame(1, $overflow->page);
        self::assertSame(1, $data->executeCalls);
        self::assertSame("DATA SQL\nORDER BY `id` DESC\nLIMIT :__pagination_limit\nOFFSET :__pagination_offset", $overflowPdo->preparedSql[2]);
        self::assertSame(['data' => [], 'pagination' => ['page' => 1, 'per_page' => 2, 'total' => 10, 'filtered' => 3, 'total_pages' => 2, 'has_next' => true, 'has_previous' => false, 'sort_by' => 'created_at', 'sort_direction' => 'DESC']], $overflow->toArray());
        self::assertArrayNotHasKey('offset', $overflow->toArray()['pagination']);
    }

    #[DataProvider('noParserExamples')]
    public function testConstructorAcceptsApprovedNoParserExamples(string $dataSql): void
    {
        $descriptor = new PdoPaginationQueryDescriptor('SELECT COUNT(*) AS total_count', [], 'SELECT COUNT(*) AS filtered_count', [], $dataSql, []);

        self::assertSame($dataSql, $descriptor->dataSql);
    }

    /** @return iterable<string, array{string}> */
    public static function noParserExamples(): iterable
    {
        yield 'missing placeholder correspondence' => ['SELECT id FROM items WHERE tenant_id = :missing'];
        yield 'unused parameter possible' => ['SELECT id FROM items'];
        yield 'repeated named placeholder' => ['SELECT id FROM items WHERE a = :x OR b = :x'];
        yield 'positional placeholder' => ['SELECT id FROM items WHERE a = ?'];
        yield 'mixed placeholders' => ['SELECT id FROM items WHERE a = ? AND b = :b'];
        yield 'caller order by' => ['SELECT id FROM items ORDER BY id'];
        yield 'caller limit' => ['SELECT id FROM items LIMIT 10'];
        yield 'malformed without semicolon or reserved prefix' => ['THIS IS NOT VALID SQL'];
    }

    private function descriptor(): PdoPaginationQueryDescriptor
    {
        return new PdoPaginationQueryDescriptor('TOTAL SQL', [], 'FILTERED SQL', [], 'DATA SQL', []);
    }

    private function config(bool $duplicate = false): PaginationConfig
    {
        return new PaginationConfig(
            new SortWhitelist(['created_at' => $duplicate ? 'id' : 'created_at', 'id' => 'id']),
            'created_at',
            SortDirectionEnum::DESC,
            'id',
            SortDirectionEnum::ASC,
            2,
            1,
            50,
        );
    }
}
