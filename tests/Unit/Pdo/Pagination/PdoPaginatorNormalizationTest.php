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

final class PdoPaginatorNormalizationTest extends TestCase
{
    private function descriptor(): PdoPaginationQueryDescriptor { return new PdoPaginationQueryDescriptor('SELECT COUNT(*) AS total_count',[],'SELECT COUNT(*) AS filtered_count',[],'SELECT id FROM t',[]); }
    private function config(): PaginationConfig { return new PaginationConfig(new SortWhitelist(['id'=>'id','created_at'=>'created_at','alias_id'=>'id']),'created_at',SortDirectionEnum::DESC,'id',SortDirectionEnum::ASC,20,2,50); }
    private function pdo(int $filtered=100, array $rows=[['id'=>1]]): ScriptedPdo { return new ScriptedPdo([ScriptedPdoStatement::count(100), ScriptedPdoStatement::count($filtered), ScriptedPdoStatement::data($rows)]); }

    #[DataProvider('pages')] public function testPageNormalization(int|string|null $input,int $expected): void { $r=(new PdoPaginator())->paginate($this->pdo(PHP_INT_MAX,[['id'=>1]]),$this->descriptor(),new PageRequest($input,20),$this->config(),fn(array $row): array=>$row); self::assertSame($expected,$r->page); }
    public static function pages(): iterable { yield [null,1]; yield ['',1]; yield [' ',1]; yield ['abc',1]; yield ['1.2',1]; yield ['1e2',1]; yield ['+',1]; yield [0,1]; yield [-1,1]; yield ['-1',1]; yield [1,1]; yield ['+1',1]; yield ['0002',2]; yield [3,3]; yield [PHP_INT_MAX,PHP_INT_MAX]; yield [(string)PHP_INT_MAX,PHP_INT_MAX]; yield [str_repeat('9',30),1]; yield ['-'.str_repeat('9',30),1]; }
    #[DataProvider('perPages')] public function testPerPageNormalization(int|string|null $input,int $expected): void { $r=(new PdoPaginator())->paginate($this->pdo(),$this->descriptor(),new PageRequest(1,$input),$this->config(),fn(array $row): array=>$row); self::assertSame($expected,$r->perPage); }
    public static function perPages(): iterable { yield [null,20]; yield ['',20]; yield ['bad',20]; yield [str_repeat('9',30),20]; yield ['-'.str_repeat('9',30),20]; yield [-1,2]; yield [0,2]; yield [1,2]; yield [2,2]; yield [25,25]; yield [50,50]; yield [51,50]; yield [PHP_INT_MAX,50]; yield [(string)PHP_INT_MAX,50]; yield ['0005',5]; }
    #[DataProvider('sorts')] public function testSortNormalization(?string $sort,?string $dir,string $expectedSort,string $expectedDir): void { $r=(new PdoPaginator())->paginate($this->pdo(),$this->descriptor(),new PageRequest(1,20,$sort,$dir),$this->config(),fn(array $row): array=>$row); self::assertSame($expectedSort,$r->sortBy); self::assertSame($expectedDir,$r->sortDirection->value); }
    public static function sorts(): iterable { yield [null,null,'created_at','DESC']; yield ['', '', 'created_at','DESC']; yield [' bad ','bad','created_at','DESC']; yield ['ID','asc','created_at','ASC']; yield [' id ','dEsC','id','DESC']; }
}
