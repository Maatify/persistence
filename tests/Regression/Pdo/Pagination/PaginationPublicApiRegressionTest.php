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

final class PaginationPublicApiRegressionTest extends TestCase
{
    public function testClassesEnumConstructorsAndPaginateSignature(): void { foreach([PageRequest::class,PageResult::class,PaginationConfig::class,PdoPaginationQueryDescriptor::class,PdoPaginator::class,SortWhitelist::class] as $c){ self::assertTrue((new ReflectionClass($c))->isFinal()); } self::assertSame(['ASC','DESC'], array_map(fn($c)=>$c->name, SortDirectionEnum::cases())); $ctor=(new ReflectionClass(PdoPaginationQueryDescriptor::class))->getConstructor(); self::assertSame(['totalSql','totalParams','filteredCountSql','filteredCountParams','dataSql','dataParams'], array_map(fn($p)=>$p->getName(), $ctor->getParameters())); $m=(new ReflectionClass(PdoPaginator::class))->getMethod('paginate'); self::assertSame(['pdo','query','request','config','mapper'], array_map(fn($p)=>$p->getName(), $m->getParameters())); self::assertSame(PageResult::class, (string)$m->getReturnType()); }
    public function testResultEnvelopeAndNoOffset(): void { $r=new PageResult([],1,20,0,0,0,false,false,'id',SortDirectionEnum::ASC); self::assertSame(['data','pagination'], array_keys($r->toArray())); self::assertSame(['page','per_page','total','filtered','total_pages','has_next','has_previous','sort_by','sort_direction'], array_keys($r->toArray()['pagination'])); self::assertArrayNotHasKey('offset',$r->toArray()['pagination']); }
}
