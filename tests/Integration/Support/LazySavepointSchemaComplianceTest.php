<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Support;

use Maatify\Persistence\Tests\Support\MySql\LazySavepointSchema;
use Maatify\Persistence\Tests\Support\MySql\MySqlConnectionFactory;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reads the schema the lazy-savepoint tests actually run against from
 * information_schema (PACKAGE_BUILDING_STANDARD section 6) and pins the declared
 * structure: PRIMARY KEY (id), table and column COMMENTs, and the exact set of
 * secondary index declarations. It guards index declarations only; it does not
 * freeze optimizer plans or EXPLAIN output and does not execute any locking
 * scenario. The lock, 1062/1205/1213 and savepoint behaviour is proved by
 * ScopedOrderingManagerLazySavepointTest.
 */
final class LazySavepointSchemaComplianceTest extends TestCase
{
    private PDO $pdo;
    private LazySavepointSchema $schema;

    protected function setUp(): void
    {
        $this->pdo = (new MySqlConnectionFactory())->create();
        $this->schema = new LazySavepointSchema($this->pdo);
        $this->schema->initialize();
    }

    protected function tearDown(): void
    {
        $this->schema->drop();
    }

    /**
     * @return array<string, array{0: string, 1: array<string, array{unique: bool, columns: list<string>}>}>
     */
    public static function tables(): array
    {
        $none = [];

        return [
            'plain' => [LazySavepointSchema::PLAIN, $none],
            'unique' => [LazySavepointSchema::UNIQUE, ['u_updated' => ['unique' => true, 'columns' => ['updated_at']]]],
            'audit' => [LazySavepointSchema::AUDIT, $none],
            'marker' => [LazySavepointSchema::MARKER, $none],
            'fill' => [LazySavepointSchema::FILL, $none],
        ];
    }

    /**
     * @param array<string, array{unique: bool, columns: list<string>}> $expectedSecondary
     */
    #[DataProvider('tables')]
    public function testTableHasIdPrimaryKeyTableAndColumnCommentsAndOnlyTheDocumentedSecondaryIndexes(
        string $table,
        array $expectedSecondary,
    ): void {
        $tableComment = $this->scalar(
            'SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );
        self::assertNotSame('', trim($tableComment), $table . ' needs a table COMMENT');

        $uncommented = $this->column(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TRIM(COLUMN_COMMENT) = '' ORDER BY ORDINAL_POSITION",
            [$table],
        );
        self::assertSame([], $uncommented, $table . ' has columns without a COMMENT');

        $from = ' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX';
        $names = $this->column('SELECT INDEX_NAME' . $from, [$table]);
        $nonUnique = $this->column('SELECT CAST(NON_UNIQUE AS CHAR)' . $from, [$table]);
        $columns = $this->column('SELECT COLUMN_NAME' . $from, [$table]);
        self::assertCount(count($names), $nonUnique);
        self::assertCount(count($names), $columns);

        /** @var array<string, array{unique: bool, columns: list<string>}> $indexes */
        $indexes = [];

        foreach ($names as $position => $name) {
            $indexes[$name] ??= ['unique' => $nonUnique[$position] === '0', 'columns' => []];
            $indexes[$name]['columns'][] = $columns[$position];
        }

        self::assertSame(['unique' => true, 'columns' => ['id']], $indexes['PRIMARY'] ?? null, $table . ' needs PRIMARY KEY (id)');
        unset($indexes['PRIMARY']);
        self::assertSame($expectedSecondary, $indexes, $table . ': secondary indexes differ from the documented set');
    }

    /**
     * @param list<string> $params
     */
    private function scalar(string $sql, array $params): string
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        self::assertIsString($value);

        return $value;
    }

    /**
     * @param list<string> $params
     *
     * @return list<string>
     */
    private function column(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $values = [];

        while (($value = $statement->fetchColumn()) !== false) {
            self::assertIsString($value);
            $values[] = $value;
        }

        return $values;
    }
}
