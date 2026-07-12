<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Unit\Pdo\Pagination;

use Maatify\Persistence\Exception\InvalidPaginationConfigurationException;
use Maatify\Persistence\Exception\InvalidPaginationQueryException;
use Maatify\Persistence\Exception\PaginationExecutionException;
use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PageResult;
use Maatify\Persistence\Pdo\Pagination\PaginationConfig;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Pdo\Pagination\SortDirectionEnum;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist;
use Maatify\Persistence\Tests\Support\Pdo\Pagination\ScriptedPdo;
use Maatify\Persistence\Tests\Support\Pdo\Pagination\ScriptedPdoStatement;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdoPaginationQueryDescriptorTest extends TestCase
{
    public function testValidDescriptorPreservesSqlAndMaps(): void { $d=new PdoPaginationQueryDescriptor(' SELECT COUNT(*) AS total_count ',['a'=>1],'SELECT COUNT(*) AS filtered_count',['b'=>'1.23'],'SELECT id FROM t',['c'=>true,'d'=>null]); self::assertSame(' SELECT COUNT(*) AS total_count ',$d->totalSql); self::assertSame(['a'=>1],$d->totalParams); self::assertSame(['b'=>'1.23'],$d->filteredCountParams); self::assertSame(['c'=>true,'d'=>null],$d->dataParams); }
    #[DataProvider('badSql')] public function testSqlRejection(string $sql): void { $this->expectException(InvalidPaginationQueryException::class); new PdoPaginationQueryDescriptor($sql,[],'SELECT 1 AS filtered_count',[],'SELECT id FROM t',[]); }
    public static function badSql(): iterable { foreach(['','   ',';SELECT 1','SELECT 1; SELECT 2','SELECT 1;','SELECT :__pagination_limit','SELECT :__pagination_offset','SELECT :__pagination_custom'] as $s) yield [$s]; }
    #[DataProvider('badParams')] public function testParamRejection(array $params): void { $this->expectException(InvalidPaginationQueryException::class); new PdoPaginationQueryDescriptor('SELECT COUNT(*) AS total_count',$params,'SELECT COUNT(*) AS filtered_count',[],'SELECT id FROM t',[]); }
    public static function badParams(): iterable { $r=fopen('php://memory','r'); yield [[1=>'x']]; yield [[''=>'x']]; yield [[':a'=>'x']]; yield [['1a'=>'x']]; yield [['a b'=>'x']]; yield [['a-b'=>'x']]; yield [['__pagination_limit'=>1]]; yield [['a'=>1.2]]; yield [['a'=>[]]]; yield [['a'=>new \stdClass()]]; yield [['a'=>$r]]; }
    #[DataProvider('noParser')] public function testNoParserContractAllowsNonPreflightSql(string $sql): void { $d=new PdoPaginationQueryDescriptor('SELECT COUNT(*) AS total_count',[],'SELECT COUNT(*) AS filtered_count',[],$sql,[]); self::assertSame($sql,$d->dataSql); }
    public static function noParser(): iterable { foreach(['SELECT :missing','SELECT id FROM t','SELECT :x + :x','SELECT ?','SELECT ? AND :x','SELECT id FROM t ORDER BY id','SELECT id FROM t LIMIT 1','MALFORMED SQL'] as $s) yield [$s]; }
}
