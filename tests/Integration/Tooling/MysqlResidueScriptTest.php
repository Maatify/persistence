<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Tooling;

use Maatify\Persistence\Tests\Support\MySql\MySqlConnectionFactory;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves scripts/check-mysql-residue.php fails closed for every kind of
 * package-owned object (table, view, trigger, function, procedure, event)
 * under every registered prefix, ignores objects it does not own, and only
 * reports: it never drops anything.
 *
 * Schema policy of the probe tables (PACKAGE_BUILDING_STANDARD §6): each is a
 * single-purpose throwaway table with a surrogate `id` primary key and no other
 * access path, so no further index is needed. There is no soft delete, display
 * order, foreign key or uniqueness policy beyond the primary key, and no host
 * table is referenced. Every column and table carries a COMMENT.
 */
final class MysqlResidueScriptTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function objectKinds(): iterable
    {
        /** @var list<non-empty-string> $prefixes */
        $prefixes = require dirname(__DIR__, 3) . '/scripts/mysql-package-objects.php';

        foreach (['table', 'view', 'trigger', 'function', 'procedure', 'event'] as $kind) {
            foreach ($prefixes as $prefix) {
                yield $kind . ' / ' . $prefix => [$kind, $prefix, 'Remaining ' . self::label($kind) . ':'];
            }
        }
    }

    #[DataProvider('objectKinds')]
    public function testReportsEachKindOfPackageOwnedObjectAndLeavesEveryObjectInPlace(
        string $kind,
        string $prefix,
        string $expectedLabel,
    ): void {
        $pdo = (new MySqlConnectionFactory())->create();
        $suffix = bin2hex(random_bytes(6));
        $owned = $prefix . 'residue_probe_' . $suffix;
        $base = 'host_app_residue_base_' . $suffix;

        try {
            $pdo->exec(
                'CREATE TABLE ' . $base . ' ('
                . "id INT NOT NULL COMMENT 'Surrogate key of the throwaway host-style probe row. No FK.',"
                . ' PRIMARY KEY (id)'
                . ") ENGINE=InnoDB COMMENT='Throwaway host-style table for the residue script test; must never be reported.'"
            );
            $pdo->exec($this->createStatement($kind, $owned, $base));

            [$exitCode, $stdout, $stderr] = $this->runScript();

            self::assertSame(1, $exitCode, $stdout . $stderr);
            self::assertStringContainsString($expectedLabel, $stderr);
            self::assertMatchesRegularExpression(
                '/' . preg_quote($expectedLabel, '/') . '[^\n]*\b' . preg_quote($owned, '/') . '\b/',
                $stderr,
            );
            self::assertStringNotContainsString($base, $stderr, 'objects the package does not own must not be reported');
            self::assertTrue($this->exists($pdo, $kind, $owned), 'the check is read-only and must not drop what it reports');
            self::assertTrue($this->exists($pdo, 'table', $base));
        } finally {
            $this->drop($pdo, $kind, $owned);
            $pdo->exec('DROP TABLE IF EXISTS ' . $base);
        }

        [, $stdout, $stderr] = $this->runScript();

        self::assertStringNotContainsString($owned, $stdout . $stderr, 'a removed object is no longer reported');
    }

    private static function label(string $kind): string
    {
        return match ($kind) {
            'table', 'view' => 'tables and views',
            'trigger' => 'triggers',
            'function', 'procedure' => 'routines',
            default => 'events',
        };
    }

    private function createStatement(string $kind, string $owned, string $base): string
    {
        return match ($kind) {
            'table' => 'CREATE TABLE ' . $owned . ' ('
                . "id INT NOT NULL COMMENT 'Surrogate key of the throwaway probe row.',"
                . ' PRIMARY KEY (id)'
                . ") ENGINE=InnoDB COMMENT='Throwaway package-owned probe table for the residue script test.'",
            'view' => 'CREATE VIEW ' . $owned . ' AS SELECT id FROM ' . $base,
            'trigger' => 'CREATE TRIGGER ' . $owned . ' BEFORE INSERT ON ' . $base . ' FOR EACH ROW SET NEW.id = NEW.id',
            'function' => 'CREATE FUNCTION ' . $owned . '() RETURNS INT DETERMINISTIC RETURN 1',
            'procedure' => 'CREATE PROCEDURE ' . $owned . '() SELECT 1',
            default => 'CREATE EVENT ' . $owned . ' ON SCHEDULE EVERY 1 YEAR DISABLE DO SELECT 1',
        };
    }

    private function drop(PDO $pdo, string $kind, string $name): void
    {
        $keyword = match ($kind) {
            'table' => 'TABLE',
            'view' => 'VIEW',
            'trigger' => 'TRIGGER',
            'function' => 'FUNCTION',
            'procedure' => 'PROCEDURE',
            default => 'EVENT',
        };
        $pdo->exec('DROP ' . $keyword . ' IF EXISTS ' . $name);
    }

    private function exists(PDO $pdo, string $kind, string $name): bool
    {
        $sql = match ($kind) {
            'table', 'view' => 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            'trigger' => 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            'function', 'procedure' => 'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = ?',
            default => 'SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() AND EVENT_NAME = ?',
        };
        $statement = $pdo->prepare($sql);
        $statement->execute([$name]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @return array{int, string, string}
     */
    private function runScript(): array
    {
        $stdoutFile = tempnam(sys_get_temp_dir(), 'residue-out-');
        $stderrFile = tempnam(sys_get_temp_dir(), 'residue-err-');
        self::assertIsString($stdoutFile);
        self::assertIsString($stderrFile);

        try {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__, 3) . '/scripts/check-mysql-residue.php'],
                [1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']],
                $pipes,
            );
            self::assertIsResource($process);

            $exitCode = proc_close($process);
            $stdout = file_get_contents($stdoutFile);
            $stderr = file_get_contents($stderrFile);
            self::assertIsString($stdout);
            self::assertIsString($stderr);

            return [$exitCode, $stdout, $stderr];
        } finally {
            unlink($stdoutFile);
            unlink($stderrFile);
        }
    }
}
