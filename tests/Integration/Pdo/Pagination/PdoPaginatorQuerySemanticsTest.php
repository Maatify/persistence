<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Pdo\Pagination;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Tests\Support\MySql\PaginationIntegrationTestCase;
use Maatify\Persistence\Tests\Support\MySql\PaginationSchemaManager;

final class PdoPaginatorQuerySemanticsTest extends PaginationIntegrationTestCase
{
    public function testTotalFilteredSortingOverflowMapperAndFilteredGreaterThanTotal(): void { $this->fixture->seedDefault(); $r=(new PdoPaginator())->paginate($this->pdo(),$this->descriptor(),new PageRequest(1,2,'score','asc'),$this->config(),fn(array $row): array=>$row); self::assertSame(4,$r->total); self::assertSame(3,$r->filtered); self::assertSame([1,2], array_column($r->data,'id')); $overflow=(new PdoPaginator())->paginate($this->pdo(),$this->descriptor(),new PageRequest(9,2),$this->config(),fn(array $row): object=>(object)$row); self::assertSame(1,$overflow->page); self::assertIsObject($overflow->data[0]); $t=PaginationSchemaManager::TABLE; $weird=new PdoPaginationQueryDescriptor("SELECT COUNT(*) AS total_count FROM `$t` WHERE tenant_id = 99",[],"SELECT COUNT(*) AS filtered_count FROM `$t` WHERE tenant_id = 1",[],"SELECT id FROM `$t` WHERE tenant_id = 1",[]); self::assertGreaterThan($weird->totalSql===''?0:-1,(new PdoPaginator())->paginate($this->pdo(),$weird,new PageRequest(),$this->config(),fn(array $row): array=>$row)->filtered); }
    public function testNoFilterIdenticalCountsNullBindingAndInvalidSortFallback(): void { $this->fixture->seedDefault(); $t=PaginationSchemaManager::TABLE; $q=new PdoPaginationQueryDescriptor("SELECT COUNT(*) AS total_count FROM `$t` WHERE tenant_id = :tenant AND category IS NULL",['tenant'=>1],"SELECT COUNT(*) AS filtered_count FROM `$t` WHERE tenant_id = :tenant2 AND category <=> :category",['tenant2'=>1,'category'=>null],"SELECT id, name FROM `$t` WHERE tenant_id = :tenant3 AND category <=> :category3",['tenant3'=>1,'category3'=>null]); $r=(new PdoPaginator())->paginate($this->pdo(),$q,new PageRequest(1,10,'unknown','bad'),$this->config(),fn(array $row): array=>$row); self::assertSame($r->total,$r->filtered); self::assertSame('created_at',$r->sortBy); }
}
