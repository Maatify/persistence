<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\MySql;

use PDO;
use PHPUnit\Framework\AssertionFailedError;

final readonly class PaginationSchemaManager
{
    public const TABLE = 'maa_persistence_test_pagination_items';
    public function __construct(private PDO $pdo) {}
    public function initialize(): void { $this->dropTables(); $sql=file_get_contents(dirname(__DIR__,2).'/Fixtures/MySql/create_pagination_items_table.sql'); if(!is_string($sql)||$sql===''){ throw new AssertionFailedError('Unable to read pagination fixture.'); } $this->pdo->exec($sql); }
    public function reset(): void { $this->pdo->exec('TRUNCATE TABLE `'.self::TABLE.'`'); }
    public function dropTables(): void { $this->pdo->exec('DROP TABLE IF EXISTS `'.self::TABLE.'`'); }
}
