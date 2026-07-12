<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Pdo\Pagination;

use Maatify\Persistence\Exception\PaginationExecutionException;
use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Tests\Support\MySql\PaginationIntegrationTestCase;
use Maatify\Persistence\Tests\Support\MySql\PaginationSchemaManager;
use PDOException;
use RuntimeException;

final class PdoPaginatorFailureContractTest extends PaginationIntegrationTestCase
{
    public function testCountShapeFailuresAndPlaceholderViolationsSurfaceAtExecution(): void { $this->fixture->seedDefault(); $t=PaginationSchemaManager::TABLE; foreach([new PdoPaginationQueryDescriptor("SELECT id AS total_count FROM `$t` WHERE tenant_id=999",[],"SELECT COUNT(*) AS filtered_count FROM `$t`",[],"SELECT id FROM `$t`",[]), new PdoPaginationQueryDescriptor("SELECT id AS total_count FROM `$t`",[],"SELECT COUNT(*) AS filtered_count FROM `$t`",[],"SELECT id FROM `$t`",[]), new PdoPaginationQueryDescriptor("SELECT id, name FROM `$t` LIMIT 1",[],"SELECT COUNT(*) AS filtered_count FROM `$t`",[],"SELECT id FROM `$t`",[])] as $q){ try{(new PdoPaginator())->paginate($this->pdo(),$q,new PageRequest(),$this->config(),fn(array $row): array=>$row); self::fail('expected');}catch(PaginationExecutionException){self::assertTrue(true);} } $this->expectException(PDOException::class); (new PdoPaginator())->paginate($this->pdo(),new PdoPaginationQueryDescriptor('SELECT COUNT(*) AS total_count WHERE :missing = 1',[],'SELECT COUNT(*) AS filtered_count',[],'SELECT 1 AS id',[]),new PageRequest(),$this->config(),fn(array $row): array=>$row); }
    public function testMapperAndPdoThrowablePropagationAndInvalidMapper(): void { $this->fixture->seedDefault(); $e=new RuntimeException('mapper'); try{(new PdoPaginator())->paginate($this->pdo(),$this->descriptor(),new PageRequest(),$this->config(),fn(array $row)=>throw $e); self::fail();}catch(RuntimeException $caught){ self::assertSame($e,$caught); } $this->expectException(PaginationExecutionException::class); (new PdoPaginator())->paginate($this->pdo(),$this->descriptor(),new PageRequest(),$this->config(),fn(array $row): int=>1); }
    public function testZeroFilteredRowsSkipsGenuineZeroRowDataQuery(): void { $this->fixture->seedDefault(); $t=PaginationSchemaManager::TABLE; $q=new PdoPaginationQueryDescriptor("SELECT COUNT(*) AS total_count FROM `$t`",[],"SELECT COUNT(*) AS filtered_count FROM `$t` WHERE tenant_id = :tenant",['tenant'=>999],"SELECT id FROM `$t` WHERE tenant_id = :tenant_data",['tenant_data'=>999]); $called=false; $r=(new PdoPaginator())->paginate($this->pdo(),$q,new PageRequest(),$this->config(),function(array $row) use (&$called): array { $called=true; return $row; }); self::assertSame([],$r->data); self::assertFalse($called); }
}
