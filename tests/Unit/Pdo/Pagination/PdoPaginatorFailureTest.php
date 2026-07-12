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

final class PdoPaginatorFailureTest extends TestCase
{
    private function q(): PdoPaginationQueryDescriptor { return new PdoPaginationQueryDescriptor('T',['p'=>1],'F',['p'=>1],'D',['p'=>1]); }
    private function c(): PaginationConfig { return new PaginationConfig(new SortWhitelist(['id'=>'id']),'id',SortDirectionEnum::ASC,'id',SortDirectionEnum::ASC); }
    #[DataProvider('failurePdos')] public function testPackageOwnedFailuresThrow(ScriptedPdo $pdo): void { try { (new PdoPaginator())->paginate($pdo,$this->q(),new PageRequest(),$this->c(),fn(array $row): array=>$row); self::fail('expected'); } catch (PaginationExecutionException) { self::assertNotSame([], $pdo->preparedSql); } }
    public static function failurePdos(): iterable { yield [new ScriptedPdo([false])]; yield [new ScriptedPdo([ScriptedPdoStatement::count(1), false])]; yield [new ScriptedPdo([ScriptedPdoStatement::count(1), ScriptedPdoStatement::count(1), false])]; yield [new ScriptedPdo([new ScriptedPdoStatement(1,[[1],false],true,'00000',[':p'=>false])])]; yield [new ScriptedPdo([ScriptedPdoStatement::count(1), new ScriptedPdoStatement(1,[[1],false],true,'00000',[':p'=>false])])]; yield [new ScriptedPdo([ScriptedPdoStatement::count(1),ScriptedPdoStatement::count(1),new ScriptedPdoStatement(1,[['id'=>1],false],true,'00000',[':p'=>false])])]; yield [new ScriptedPdo([ScriptedPdoStatement::count(1),ScriptedPdoStatement::count(1),new ScriptedPdoStatement(1,[['id'=>1],false],true,'00000',[':__pagination_limit'=>false])])]; yield [new ScriptedPdo([ScriptedPdoStatement::count(1),ScriptedPdoStatement::count(1),new ScriptedPdoStatement(1,[['id'=>1],false],true,'00000',[':__pagination_offset'=>false])])]; yield [new ScriptedPdo([new ScriptedPdoStatement(1,[[1],false],false)])]; yield [new ScriptedPdo([ScriptedPdoStatement::count(1),new ScriptedPdoStatement(1,[[1],false],false)])]; yield [new ScriptedPdo([ScriptedPdoStatement::count(1),ScriptedPdoStatement::count(1),new ScriptedPdoStatement(1,[['id'=>1],false],false)])]; }
    #[DataProvider('badFetchAndCounts')] public function testFetchAndCountRules(ScriptedPdo $pdo): void { $this->expectException(PaginationExecutionException::class); (new PdoPaginator())->paginate($pdo,new PdoPaginationQueryDescriptor('T',[],'F',[],'D',[]),new PageRequest(),$this->c(),fn(array $row): array=>$row); }
    public static function badFetchAndCounts(): iterable { yield [new ScriptedPdo([new ScriptedPdoStatement(1,[false],true,'HY000')])]; yield [new ScriptedPdo([new ScriptedPdoStatement(1,[],true,null)])]; yield [new ScriptedPdo([new ScriptedPdoStatement(1,[[1],[2],false])])]; yield [new ScriptedPdo([new ScriptedPdoStatement(0,[[1],false])])]; yield [new ScriptedPdo([new ScriptedPdoStatement(2,[[1],false])])]; foreach([false,null,'','-1','1.2','1e2',PHP_INT_MAX.'0',[]] as $v) yield [new ScriptedPdo([new ScriptedPdoStatement(1,[[$v],false])])]; yield [new ScriptedPdo([ScriptedPdoStatement::count(1),ScriptedPdoStatement::count(1),new ScriptedPdoStatement(1,[['id'=>1],false],true,'HY000')])]; yield [new ScriptedPdo([ScriptedPdoStatement::count(1),ScriptedPdoStatement::count(1),new ScriptedPdoStatement(1,[123,false])])]; yield [new ScriptedPdo([ScriptedPdoStatement::count(1),ScriptedPdoStatement::count(1),new ScriptedPdoStatement(1,[[1=>'x'],false])])]; yield [new ScriptedPdo([ScriptedPdoStatement::count(1),ScriptedPdoStatement::count(1),new ScriptedPdoStatement(1,[[],false])])]; }
    public function testThrowableIdentityPropagation(): void { $e=new PDOException('x'); $pdo=new ScriptedPdo([$e]); try{(new PdoPaginator())->paginate($pdo,new PdoPaginationQueryDescriptor('T',[],'F',[],'D',[]),new PageRequest(),$this->c(),fn(array $row): array=>$row); self::fail();}catch(PDOException $caught){self::assertSame($e,$caught);} $m=new RuntimeException('m'); try{(new PdoPaginator())->paginate(new ScriptedPdo([ScriptedPdoStatement::count(1),ScriptedPdoStatement::count(1),ScriptedPdoStatement::data([['id'=>1]])]),new PdoPaginationQueryDescriptor('T',[],'F',[],'D',[]),new PageRequest(),$this->c(),fn(array $row)=>throw $m); self::fail();}catch(RuntimeException $caught){self::assertSame($m,$caught);} }
    public function testInvalidMapperResultThrows(): void { $this->expectException(PaginationExecutionException::class); (new PdoPaginator())->paginate(new ScriptedPdo([ScriptedPdoStatement::count(1),ScriptedPdoStatement::count(1),ScriptedPdoStatement::data([['id'=>1]])]),new PdoPaginationQueryDescriptor('T',[],'F',[],'D',[]),new PageRequest(),$this->c(),fn(array $row): int=>1); }
}
