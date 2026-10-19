<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Examples;

use Maatify\Persistence\Tests\Support\MySql\MySqlConnectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every standalone movement example under examples/move-within-scope (the
 * moveWithinScope() stale-snapshot scenario of Issue #33) as a real PHP process
 * against the configured MySQL database. Each script asserts its own
 * documented behavior and prints `OK: <name>` only when all of it was observed,
 * so a broken example or a behavior drift fails this suite.
 *
 * The scripts use the repository's Composer autoloader here; the same scripts
 * run against a clean, non-symlinked Composer dependency in the Consumer
 * Verification Harness (`composer test:consumer`).
 */
final class MoveWithinScopeExamplesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function exampleProvider(): array
    {
        $scripts = glob(self::examplesDirectory() . '/[0-9][0-9]_*.php');
        self::assertIsArray($scripts);

        $cases = [];
        foreach ($scripts as $script) {
            $cases[basename($script)] = [$script];
        }

        return $cases;
    }

    public function testExamplesAreDiscovered(): void
    {
        self::assertGreaterThanOrEqual(1, count(self::exampleProvider()));
    }

    #[DataProvider('exampleProvider')]
    public function testExampleRunsAgainstRealMySql(string $script): void
    {
        // Fails with the shared, explicit message when MySQL is not configured.
        (new MySqlConnectionFactory())->create();

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname($script),
        );
        self::assertIsResource($process);

        [$stdoutPipe, $stderrPipe] = self::outputPipes($pipes);

        $stdout = (string) stream_get_contents($stdoutPipe);
        $stderr = (string) stream_get_contents($stderrPipe);
        fclose($stdoutPipe);
        fclose($stderrPipe);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, 'Example failed: ' . basename($script) . PHP_EOL . $stdout . $stderr);
        self::assertStringContainsString('OK: ' . basename($script, '.php'), $stdout);
        self::assertSame('', $stderr);
    }

    /**
     * Takes `mixed` because proc_open() fills the by-reference array and
     * PHPStan versions differ in how they type it.
     *
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

    private static function examplesDirectory(): string
    {
        return dirname(__DIR__, 3) . '/examples/move-within-scope';
    }
}
