<?php

declare(strict_types=1);

/**
 * Shared helpers for the deadlock-retry examples.
 *
 * Every example is a standalone script: `php examples/deadlock-retry/<file>.php`.
 * The helpers only provide what is not part of the package: a PDO connection
 * from the environment, a temporary example table, a second process that forms
 * a REAL InnoDB deadlock with the example's transaction, and small assertions
 * that make each script fail loudly (non-zero exit) when the documented
 * behavior is not observed. None of this is package API.
 *
 * The package is resolved through Composer's autoloader at `../../vendor`,
 * relative to this directory. In this repository that is the development
 * install; in the Consumer Verification Harness the examples are copied into a
 * clean consumer project, where it is the dependency installed by Composer.
 */

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Composer autoload not found. Run `composer install` first.\n");

    exit(2);
}

require $autoload;

/**
 * Opens a MySQL connection from the same environment variables used by the
 * package's integration tests, with the recommended PDO settings.
 */
function example_connect(): PDO
{
    $dsn = getenv('PERSISTENCE_TEST_MYSQL_DSN');
    $user = getenv('PERSISTENCE_TEST_MYSQL_USER');
    $password = getenv('PERSISTENCE_TEST_MYSQL_PASSWORD');

    if (! is_string($dsn) || $dsn === '' || ! is_string($user) || $user === '') {
        fwrite(
            STDERR,
            'Set PERSISTENCE_TEST_MYSQL_DSN, PERSISTENCE_TEST_MYSQL_USER and '
            . "PERSISTENCE_TEST_MYSQL_PASSWORD to a real MySQL test database.\n",
        );

        exit(2);
    }

    $pdo = new PDO($dsn, $user, is_string($password) ? $password : '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('These examples require the MySQL PDO driver.');
    }

    return $pdo;
}

/**
 * Creates a uniquely named example table with two contended rows (ids 1 and 2),
 * runs the scenario, and always drops the table, also when the scenario fails.
 * The scenario's own failure wins over a cleanup failure.
 *
 * @param callable(PDO, non-empty-string): void $scenario
 */
function example_with_table(callable $scenario): void
{
    $pdo = example_connect();
    $table = 'maa_example_deadlock_retry_' . bin2hex(random_bytes(6));
    $created = false;
    $failure = null;

    try {
        $pdo->exec(
            'CREATE TABLE ' . $table . ' (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT "Row identity.",
                label VARCHAR(64) NOT NULL COMMENT "Row label.",
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Temporary example rows."',
        );
        $created = true;
        $pdo->exec('INSERT INTO ' . $table . ' (label) VALUES ("contended-a"), ("contended-b")');

        $scenario($pdo, $table);
    } catch (Throwable $throwable) {
        $failure = $throwable;
    }

    $cleanupFailure = null;

    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($created) {
            $pdo->exec('SET SESSION lock_wait_timeout = 10');
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
    } catch (Throwable $throwable) {
        $cleanupFailure = $throwable;
    }

    if ($failure !== null) {
        throw $failure;
    }

    if ($cleanupFailure !== null) {
        throw $cleanupFailure;
    }
}

/**
 * Locks one row of the example table on the given connection (SELECT ... FOR UPDATE).
 */
function example_lock_row(PDO $pdo, string $table, int $id): void
{
    $statement = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE id = :id FOR UPDATE');
    $statement->execute(['id' => $id]);
    $statement->fetchAll();
}

/**
 * Labels of the rows added by the scenario (everything except the two contended rows).
 *
 * @return list<string>
 */
function example_added_labels(PDO $pdo, string $table): array
{
    $statement = $pdo->query('SELECT label FROM ' . $table . ' WHERE id > 2 ORDER BY id');

    if (! $statement instanceof PDOStatement) {
        throw new RuntimeException('Unable to read the example rows.');
    }

    return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
}

/**
 * A second process with its own connection that holds row 2 inside a heavier
 * transaction (many inserted rows), so that InnoDB picks the example's lighter
 * transaction as the deadlock victim. It exists only to make the example's
 * deadlock real and deterministic; production code has no such partner.
 */
final class ExampleDeadlockPartner
{
    private string $connectionId = '';

    /**
     * @param resource             $process
     * @param array<int, resource> $pipes
     */
    private function __construct(private $process, private array $pipes)
    {
    }

    public static function start(string $table): self
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/_partner.php', $table],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the deadlock partner process.');
        }

        $partner = new self($process, $pipes);
        $ready = $partner->readLine();

        if (! str_starts_with($ready, 'READY ')) {
            throw new RuntimeException('Unexpected deadlock partner output: ' . $ready);
        }

        $partner->connectionId = substr($ready, strlen('READY '));

        return $partner;
    }

    /**
     * Lets the partner request row 1 (which the caller must already hold) and
     * waits until it is blocked on it, observed through a separate connection.
     */
    public function queueBehindRowOne(PDO $observer): void
    {
        fwrite($this->pipes[0], "go\n");
        fflush($this->pipes[0]);

        $deadline = microtime(true) + 15;

        do {
            $rows = $observer->query('SHOW FULL PROCESSLIST');

            foreach ($rows instanceof PDOStatement ? $rows->fetchAll() : [] as $row) {
                if (
                    is_array($row)
                    && (string) ($row['Id'] ?? '') === $this->connectionId
                    && str_contains((string) ($row['Info'] ?? ''), 'FOR UPDATE')
                    && (int) ($row['Time'] ?? 0) >= 1
                ) {
                    return;
                }
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('The deadlock partner never blocked on row 1.');
    }

    /** Waits for the partner's final report ("ACQUIRED" or "FAILURE <code>") and stops it. */
    public function finish(): string
    {
        try {
            return $this->readLine();
        } finally {
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_close($this->process);
        }
    }

    private function readLine(): string
    {
        stream_set_timeout($this->pipes[1], 30);
        $line = fgets($this->pipes[1]);

        if ($line === false) {
            throw new RuntimeException('The deadlock partner produced no output: ' . stream_get_contents($this->pipes[2]));
        }

        return trim($line);
    }
}

/**
 * Returns the MySQL driver error code of a PDOException (1213 deadlock), or null.
 */
function example_error_code(PDOException $exception): ?int
{
    $code = $exception->errorInfo[1] ?? null;

    return is_int($code) ? $code : null;
}

function example_say(string $message): void
{
    echo $message . PHP_EOL;
}

function example_expect_same(mixed $expected, mixed $actual, string $description): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Example expectation failed: ' . $description
            . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')',
        );
    }
}
