<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Examples;

use Maatify\Persistence\Tests\Support\MySql\MySqlConnectionFactory;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The examples promise to drop the tables they create, also when setup itself
 * fails part-way. This forces that failure: the second CREATE TABLE collides
 * with a pre-existing table, and the test proves the first table was dropped,
 * the pre-existing table was not touched, and the original error propagated.
 */
final class ExampleSchemaCleanupTest extends TestCase
{
    public function testPartialSetupFailureDropsOnlyTablesTheExampleCreated(): void
    {
        $pdo = (new MySqlConnectionFactory())->create();
        $suffix = 'cleanup_' . bin2hex(random_bytes(4));
        $playlists = 'maa_example_playlists_' . $suffix;
        $items = 'maa_example_items_' . $suffix;

        $pdo->exec('DROP TABLE IF EXISTS ' . $items);
        $pdo->exec('CREATE TABLE ' . $items . ' (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');

        $support = dirname(__DIR__, 3) . '/examples/next-position/_support.php';
        $script = tempnam(sys_get_temp_dir(), 'maatify-example-setup-');
        self::assertIsString($script);

        try {
            file_put_contents($script, <<<PHP
                <?php

                declare(strict_types=1);

                require {$this->exportString($support)};

                try {
                    example_with_schema(
                        static function (): void {
                            echo "SCENARIO-RAN\\n";
                        },
                        suffix: {$this->exportString($suffix)},
                    );
                    echo "NO-FAILURE\\n";
                } catch (PDOException \$exception) {
                    echo 'SETUP-FAILED ' . example_error_code(\$exception) . "\\n";
                }
                PHP);

            $pipes = [];
            $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            [$stdoutPipe, $stderrPipe] = self::outputPipes($pipes);
            $stdout = (string) stream_get_contents($stdoutPipe);
            $stderr = (string) stream_get_contents($stderrPipe);
            fclose($stdoutPipe);
            fclose($stderrPipe);
            $exitCode = proc_close($process);

            self::assertSame(0, $exitCode, $stdout . $stderr);
            self::assertSame("SETUP-FAILED 1050\n", $stdout, 'the original table-exists error must propagate');

            self::assertFalse($this->tableExists($pdo, $playlists), 'the table created before the failure was dropped');
            self::assertTrue($this->tableExists($pdo, $items), 'a table the example did not create must not be dropped');
        } finally {
            unlink($script);
            $pdo->exec('DROP TABLE IF EXISTS ' . $items);
            $pdo->exec('DROP TABLE IF EXISTS ' . $playlists);
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name',
        );
        $statement->execute(['name' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function exportString(string $value): string
    {
        return var_export($value, true);
    }

    /**
     * @return array{resource, resource}
     */
    private static function outputPipes(mixed $pipes): array
    {
        self::assertIsArray($pipes);
        $stdoutPipe = $pipes[1] ?? null;
        $stderrPipe = $pipes[2] ?? null;
        self::assertIsResource($stdoutPipe);
        self::assertIsResource($stderrPipe);

        return [$stdoutPipe, $stderrPipe];
    }
}
