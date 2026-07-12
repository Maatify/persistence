<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Regression\Pdo\Pagination;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PageResult;
use Maatify\Persistence\Pdo\Pagination\PaginationConfig;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Pdo\Pagination\SortDirectionEnum;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PaginationSqlContractRegressionTest extends TestCase
{
    public function testFinalSqlAssemblyAndNoGeneralParserClaims(): void { $w=new SortWhitelist(['id'=>'id','created_at'=>'created_at','alias_id'=>'id']); self::assertSame('`created_at`',$w->quotedIdentifierFor('created_at')); $q=new PdoPaginationQueryDescriptor('SELECT COUNT(*) AS total_count',[],'SELECT COUNT(*) AS filtered_count',[],'SELECT id FROM t ORDER BY caller LIMIT 1',[]); self::assertStringContainsString('ORDER BY caller',$q->dataSql); self::assertSame(['filteredCountParams'], [array_values(array_filter(array_map(fn($p)=>$p->getName(), (new ReflectionClass(PdoPaginationQueryDescriptor::class))->getConstructor()->getParameters()), fn($n)=>$n==='filteredCountParams'))[0]]); }
    public function testTieBreakerUniquenessRemainsCallerOwned(): void { $c=new PaginationConfig(new SortWhitelist(['id'=>'id','same'=>'id']),'same',SortDirectionEnum::DESC,'id',SortDirectionEnum::ASC); self::assertSame('same',$c->defaultSortBy); }
}
