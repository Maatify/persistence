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

final class PageResultTest extends TestCase
{
    public function testValidConstructionMetadataAndSerialization(): void { $o=new \stdClass(); $r=new PageResult([['id'=>1],$o],1,20,30,22,2,true,false,'created_at',SortDirectionEnum::DESC); $a=$r->toArray(); self::assertSame($a,$r->jsonSerialize()); self::assertArrayNotHasKey('offset',$a['pagination']); self::assertSame($o,$a['data'][1]); self::assertSame(2,$a['pagination']['total_pages']); }
    public function testZeroAndEmptyPositiveFiltered(): void { self::assertSame([], (new PageResult([],1,20,0,0,0,false,false,'id',SortDirectionEnum::ASC))->data); self::assertSame(2,(new PageResult([],2,20,50,50,3,true,true,'id',SortDirectionEnum::ASC))->page); }
    #[DataProvider('invalidResults')] public function testInvariantRejection(array $args): void { $this->expectException(PaginationExecutionException::class); new PageResult(...$args); }
    public static function invalidResults(): iterable { $ok=[[],1,20,0,0,0,false,false,'id',SortDirectionEnum::ASC]; yield [[['x'=>[]],1,20,0,0,0,false,false,'id',SortDirectionEnum::ASC]]; yield [[[1],1,20,1,1,1,false,false,'id',SortDirectionEnum::ASC]]; yield [[[],0,20,0,0,0,false,false,'id',SortDirectionEnum::ASC]]; yield [[[],1,0,0,0,0,false,false,'id',SortDirectionEnum::ASC]]; yield [[[],1,20,-1,0,0,false,false,'id',SortDirectionEnum::ASC]]; yield [[[],1,20,0,-1,0,false,false,'id',SortDirectionEnum::ASC]]; yield [[[],1,20,0,0,-1,false,false,'id',SortDirectionEnum::ASC]]; yield [[[[],[]],1,1,2,2,2,true,false,'id',SortDirectionEnum::ASC]]; yield [[[],1,20,0,0,0,false,false,'bad-key',SortDirectionEnum::ASC]]; yield [[[],1,20,20,20,2,false,false,'id',SortDirectionEnum::ASC]]; yield [[[['id'=>1]],1,20,0,0,0,false,false,'id',SortDirectionEnum::ASC]]; yield [[[],2,20,0,0,0,false,false,'id',SortDirectionEnum::ASC]]; yield [[[],1,20,0,0,0,true,false,'id',SortDirectionEnum::ASC]]; yield [[[],1,20,50,50,3,false,false,'id',SortDirectionEnum::ASC]]; yield [[[],1,20,50,50,3,true,true,'id',SortDirectionEnum::ASC]]; }
}
