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
 * information_schema (PACKAGE_BUILDING_STANDARD section 6) and pins the
 * documented index set: the access paths the lock and savepoint scenarios rely
 * on must not change silently. It does not execute any locking scenario; those
 * are proved by ScopedOrderingManagerLazySavepointTest.
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

        /** @var array<string, array{unique: bool, columns: list<string>}> $indexes */
        $indexes = [];
        $statement = $this->pdo->prepare(
            'SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, COLUMN_NAME AS column_name FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
        );
        $statement->execute([$table]);

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $name = $row['name'] ?? null;
            $nonUnique = $row['non_unique'] ?? null;
            $columnName = $row['column_name'] ?? null;
            self::assertIsString($name);
            self::assertIsString($columnName);
            self::assertTrue($nonUnique === 0 || $nonUnique === 1 || $nonUnique === '0' || $nonUnique === '1');
            $indexes[$name] ??= ['unique' => $nonUnique === 0 || $nonUnique === '0', 'columns' => []];
            $indexes[$name]['columns'][] = $columnName;
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
