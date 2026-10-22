<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Tooling;

use Maatify\Persistence\Tests\Support\MySql\MySqlConnectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Proves scripts/check-mysql-residue.php fails closed for package-owned
 * objects it was never told about by name, ignores objects it does not own,
 * and only reports: it never drops anything.
 */
final class MysqlResidueScriptTest extends TestCase
{
    public function testReportsPackageOwnedObjectsAndLeavesEveryObjectInPlace(): void
    {
        $pdo = (new MySqlConnectionFactory())->create();
        $suffix = bin2hex(random_bytes(6));
        $owned = 'maa_persistence_test_residue_probe_' . $suffix;
        $trigger = 'maa_persistence_test_residue_probe_trg_' . $suffix;
        $foreign = 'host_app_residue_probe_' . $suffix;

        try {
            $pdo->exec('CREATE TABLE ' . $owned . ' (id INT NOT NULL PRIMARY KEY, v INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
            $pdo->exec('CREATE TABLE ' . $foreign . ' (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
            $pdo->exec('CREATE TRIGGER ' . $trigger . ' BEFORE UPDATE ON ' . $owned . ' FOR EACH ROW SET NEW.v = OLD.v');

            [$exitCode, $stdout, $stderr] = $this->runScript();

            self::assertSame(1, $exitCode, $stdout . $stderr);
            self::assertStringContainsString($owned, $stderr);
            self::assertStringContainsString($trigger, $stderr);
            self::assertStringNotContainsString($foreign, $stderr, 'objects the package does not own must not be reported');
            self::assertTrue($this->tableExists($owned), 'the check is read-only and must not drop what it reports');
            self::assertTrue($this->tableExists($foreign));
        } finally {
            $pdo->exec('DROP TRIGGER IF EXISTS ' . $trigger);
            $pdo->exec('DROP TABLE IF EXISTS ' . $owned);
            $pdo->exec('DROP TABLE IF EXISTS ' . $foreign);
        }

        [, $stdout, $stderr] = $this->runScript();

        self::assertStringNotContainsString($owned, $stdout . $stderr, 'a removed object is no longer reported');
    }

    private function tableExists(string $table): bool
    {
        $pdo = (new MySqlConnectionFactory())->create();
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @return array{int, string, string}
     */
    private function runScript(): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/scripts/check-mysql-residue.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $stdout, (string) $stderr];
    }
}
