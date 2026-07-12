<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Pdo\Pagination;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Tests\Support\MySql\PaginationIntegrationTestCase;
use PDO;

final class PdoPaginatorTransactionTest extends PaginationIntegrationTestCase
{
    public function testAttributesUnchangedNoTransactionOutsideAndCallerTransactionPreserved(): void { $this->fixture->seedDefault(); $emulateBefore=$this->pdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES); $errBefore=$this->pdo()->getAttribute(PDO::ATTR_ERRMODE); self::assertFalse($this->pdo()->inTransaction()); (new PdoPaginator())->paginate($this->pdo(),$this->descriptor(),new PageRequest(),$this->config(),fn(array $row): array=>$row); self::assertFalse($this->pdo()->inTransaction()); self::assertSame($emulateBefore,$this->pdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES)); self::assertSame((bool)$emulateBefore,(bool)$this->pdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES)); self::assertSame($errBefore,$this->pdo()->getAttribute(PDO::ATTR_ERRMODE)); $this->pdo()->beginTransaction(); try { (new PdoPaginator())->paginate($this->pdo(),$this->descriptor(),new PageRequest(),$this->config(),fn(array $row): array=>$row); self::assertTrue($this->pdo()->inTransaction()); } finally { if($this->pdo()->inTransaction()){ $this->pdo()->rollBack(); } } }
}
