<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Examples;

use Maatify\Persistence\Tests\Support\MySql\MySqlConnectionFactory;
use PDO;
use PHPUnit\Framework\AssertionFailedError;
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
        $pdo->exec('CREATE TABLE ' . $items . ' ('
            . "id INT NOT NULL COMMENT 'Surrogate key of the pre-existing colliding probe table. No FK.',"
            . ' PRIMARY KEY (id)'
            . ") ENGINE=InnoDB COMMENT='Test-only table that makes the example second CREATE TABLE collide; must survive the cleanup.'");

        try {
            $result = $this->runExampleScript(
                <<<'PHP'
                try {
                    example_with_schema(
                        static function (): void {
                            echo "SCENARIO-RAN\n";
                        },
                        suffix: $argv[1],
                    );
                    echo "NO-FAILURE\n";
                } catch (PDOException $exception) {
                    echo 'SETUP-FAILED ' . example_error_code($exception) . "\n";
                }
                PHP,
                $suffix,
            );

            self::assertSame("SETUP-FAILED 1050\n", $result, 'the original table-exists error must propagate');
            self::assertFalse($this->tableExists($pdo, $playlists), 'the table created before the failure was dropped');
            self::assertTrue($this->tableExists($pdo, $items), 'a table the example did not create must not be dropped');
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS ' . $items);
            $pdo->exec('DROP TABLE IF EXISTS ' . $playlists);
        }
    }

    /**
     * Regression for a failure found while hardening the examples: when a
     * scenario fails while a second connection is still inside a transaction
     * that has read the example table, that connection holds a metadata lock
     * and DROP TABLE would wait for it. Cleanup must end every example
     * connection's transaction first, drop the tables, and still surface the
     * scenario's own exception. The time guard turns a hang into a failure.
     */
    public function testFailingScenarioWithOpenSecondConnectionTransactionStillCleansUp(): void
    {
        $pdo = (new MySqlConnectionFactory())->create();
        $suffix = 'cleanup_' . bin2hex(random_bytes(4));
        $playlists = 'maa_example_playlists_' . $suffix;
        $items = 'maa_example_items_' . $suffix;

        try {
            $startedAt = microtime(true);
            $result = $this->runExampleScript(
                <<<'PHP'
                try {
                    example_with_schema(
                        static function (PDO $pdo, array $tables): void {
                            $other = example_connect();
                            $other->beginTransaction();
                            // A consistent read inside the open transaction takes a metadata lock.
                            example_scalar($other, 'SELECT COUNT(*) FROM ' . $tables['items']);

                            throw new RuntimeException('scenario failed on purpose');
                        },
                        suffix: $argv[1],
                    );
                    echo "NO-FAILURE\n";
                } catch (RuntimeException $exception) {
                    echo 'ORIGINAL ' . $exception->getMessage() . "\n";
                }
                PHP,
                $suffix,
            );
            $elapsed = microtime(true) - $startedAt;

            self::assertSame("ORIGINAL scenario failed on purpose\n", $result, 'the scenario exception must propagate unchanged');
            self::assertFalse($this->tableExists($pdo, $items), 'the items table was dropped despite the open second transaction');
            self::assertFalse($this->tableExists($pdo, $playlists), 'the playlists table was dropped');
            self::assertLessThan(8.0, $elapsed, 'cleanup must not wait for a metadata lock');
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS ' . $items);
            $pdo->exec('DROP TABLE IF EXISTS ' . $playlists);
        }
    }

    public function testRunnerRejectsNonZeroExitCodeEvenWithExpectedOutput(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('must exit with code 0');

        $this->runExampleScript("echo \"EXPECTED\\n\";\nexit(7);", 'unused');
    }

    public function testRunnerFailsAHungProcessAtTheTimeGuard(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('1 second guard');

        $this->runExampleScript('sleep(30);', 'unused', 1);
    }

    /**
     * Runs a snippet in a separate PHP process with the example support file
     * loaded and the suffix as $argv[1]. Returns stdout; fails on a non-zero
     * exit code, stderr output, or when the time guard expires.
     */
    private function runExampleScript(string $body, string $suffix, int $timeoutSeconds = 60): string
    {
        $support = dirname(__DIR__, 3) . '/examples/next-position/_support.php';
        $script = tempnam(sys_get_temp_dir(), 'maatify-example-cleanup-');
        self::assertIsString($script);
        file_put_contents(
            $script,
            "<?php\n\ndeclare(strict_types=1);\n\nrequire " . var_export($support, true) . ";\n\n" . $body . "\n",
        );

        try {
            $pipes = [];
            $process = proc_open([PHP_BINARY, $script, $suffix], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            [$stdoutPipe, $stderrPipe] = self::outputPipes($pipes);
            stream_set_blocking($stdoutPipe, false);
            stream_set_blocking($stderrPipe, false);

            $stdout = '';
            $stderr = '';
            $deadline = microtime(true) + $timeoutSeconds;
            $exitCode = null;

            while (true) {
                $stdout .= (string) stream_get_contents($stdoutPipe);
                $stderr .= (string) stream_get_contents($stderrPipe);
                $status = proc_get_status($process);

                if (! $status['running']) {
                    // proc_close() returns -1 once the status was read, so keep this code.
                    $exitCode = $status['exitcode'];

                    break;
                }

                if (microtime(true) > $deadline) {
                    proc_terminate($process, 9);
                    fclose($stdoutPipe);
                    fclose($stderrPipe);
                    proc_close($process);
                    self::fail('The example process hung (' . $timeoutSeconds . ' second guard).');
                }

                usleep(50_000);
            }

            $stdout .= (string) stream_get_contents($stdoutPipe);
            $stderr .= (string) stream_get_contents($stderrPipe);
            fclose($stdoutPipe);
            fclose($stderrPipe);
            proc_close($process);

            self::assertSame(0, $exitCode, 'The example process must exit with code 0.' . PHP_EOL . $stdout . $stderr);
            self::assertSame('', $stderr, $stdout . $stderr);

            return $stdout;
        } finally {
            unlink($script);
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
