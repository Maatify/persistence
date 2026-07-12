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

final class PaginationConfigTest extends TestCase
{
    public function testValidConfigDefaultsAndCustom(): void { $w=new SortWhitelist(['id'=>'id','created_at'=>'created_at','alias'=>'id']); $c=new PaginationConfig($w,'created_at',SortDirectionEnum::DESC,'id',SortDirectionEnum::ASC); self::assertSame(20,$c->defaultPerPage); self::assertSame(1,$c->minPerPage); self::assertSame(200,$c->maxPerPage); $c2=new PaginationConfig($w,'alias',SortDirectionEnum::ASC,'id',SortDirectionEnum::DESC,10,2,50); self::assertSame(10,$c2->defaultPerPage); }
    #[DataProvider('invalidConfig')] public function testInvalidConfig(string $default,string $tie,int $def,int $min,int $max): void { $this->expectException(InvalidPaginationConfigurationException::class); new PaginationConfig(new SortWhitelist(['id'=>'id','created_at'=>'created_at']),$default,SortDirectionEnum::ASC,$tie,SortDirectionEnum::ASC,$def,$min,$max); }
    public static function invalidConfig(): iterable { yield ['id','id',20,0,200]; yield ['id','id',20,-1,200]; yield ['id','id',20,10,5]; yield ['id','id',1,2,10]; yield ['id','id',11,2,10]; yield ['bad-key','id',5,1,10]; yield ['id','bad-key',5,1,10]; yield ['missing','id',5,1,10]; yield ['id','missing',5,1,10]; yield ['','id',5,1,10]; }
}
