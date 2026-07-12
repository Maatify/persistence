<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\MySql;

use PDO;
use PHPUnit\Framework\AssertionFailedError;

final readonly class PaginationSchemaManager
{
    public const TABLE = 'maa_persistence_test_pagination_items';

    public function __construct(private PDO $pdo)
    {
    }

    public function initialize(): void
    {
        $this->dropTable();
        $this->executeFixture('create_pagination_items_table.sql');
    }

    public function reset(): void
    {
        $this->pdo->exec('TRUNCATE TABLE `' . self::TABLE . '`');
    }

    public function dropTable(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS `' . self::TABLE . '`');
    }

    /** @param non-empty-string $filename */
    private function executeFixture(string $filename): void
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/MySql/' . $filename;
        $sql = file_get_contents($path);

        if (!is_string($sql) || $sql === '') {
            throw new AssertionFailedError(sprintf('Unable to read MySQL fixture %s.', $filename));
        }

        $this->pdo->exec($sql);
    }
}
