<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\MySql;

use Maatify\Persistence\Pdo\Pagination\PaginationConfig;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\SortDirectionEnum;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class PaginationIntegrationTestCase extends TestCase
{
    protected static PDO $pdo; protected static PaginationSchemaManager $schemaManager; protected PaginationFixture $fixture;
    public static function setUpBeforeClass(): void { self::$pdo=(new MySqlConnectionFactory())->create(); self::$schemaManager=new PaginationSchemaManager(self::$pdo); self::$schemaManager->initialize(); }
    protected function setUp(): void { $this->rollBackOpenTransaction(); self::$schemaManager->reset(); $this->fixture=new PaginationFixture(self::$pdo); }
    protected function tearDown(): void { $this->rollBackOpenTransaction(); self::$schemaManager->reset(); }
    public static function tearDownAfterClass(): void { if(isset(self::$pdo)&&self::$pdo->inTransaction()){ self::$pdo->rollBack(); } if(isset(self::$schemaManager)){ self::$schemaManager->dropTables(); } }
    protected function pdo(): PDO { return self::$pdo; }
    protected function config(): PaginationConfig { return new PaginationConfig(new SortWhitelist(['id'=>'id','score'=>'score','created_at'=>'created_at']),'created_at',SortDirectionEnum::DESC,'id',SortDirectionEnum::ASC,2,1,50); }
    protected function descriptor(array $total=[], array $filtered=[], array $data=[]): PdoPaginationQueryDescriptor { $t=PaginationSchemaManager::TABLE; return new PdoPaginationQueryDescriptor("SELECT COUNT(*) AS total_count FROM `$t` WHERE tenant_id = :tenant_total",$total?:['tenant_total'=>1],"SELECT COUNT(*) AS filtered_count FROM `$t` WHERE tenant_id = :tenant_filtered AND active = :active_filtered",$filtered?:['tenant_filtered'=>1,'active_filtered'=>true],"SELECT id, tenant_id, category, active, name, score, created_at FROM `$t` WHERE tenant_id = :tenant_data AND active = :active_data",$data?:['tenant_data'=>1,'active_data'=>true]); }
    private function rollBackOpenTransaction(): void { if(isset(self::$pdo)&&self::$pdo->inTransaction()){ self::$pdo->rollBack(); } }
}
