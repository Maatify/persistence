<?php

declare(strict_types=1);

/**
 * Shared helpers for the next-position examples.
 *
 * Every example is a standalone script: `php examples/next-position/<file>.php`.
 * The helpers only provide what is not part of the package: a PDO connection
 * from the environment, temporary example tables, and small assertions that
 * make each script fail loudly (non-zero exit) when the documented behavior is
 * not observed. None of this is package API.
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
 * package's integration tests. Exception mode and native prepares are the
 * recommended PDO settings for this package.
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

    ExampleConnections::register($pdo);

    return $pdo;
}

/**
 * Remembers every connection the example opened, so cleanup can end their open
 * transactions: a connection still inside a transaction holds metadata locks
 * and would make DROP TABLE wait when an example fails mid-scenario.
 */
final class ExampleConnections
{
    /** @var list<PDO> */
    private static array $connections = [];

    public static function register(PDO $pdo): void
    {
        self::$connections[] = $pdo;
    }

    /**
     * @return list<PDO>
     */
    public static function all(): array
    {
        return self::$connections;
    }
}

/**
 * Creates uniquely named example tables, runs the scenario, and always drops
 * the tables it created, so examples never touch host tables and can be re-run.
 *
 * `items` holds ordered rows (scope column `playlist_id`, order column
 * `position`, optional soft delete). `playlists` is the parent table whose rows
 * a host can lock to serialize the first insert into an empty scope.
 *
 * Setup is inside the cleanup scope: only tables whose CREATE succeeded are
 * tracked and dropped, so a failure part-way through setup leaves nothing
 * behind and never drops a table this call did not create. The original
 * failure is rethrown; a cleanup failure is reported only when nothing else
 * failed. `$suffix` makes the table names deterministic (tests only).
 *
 * @param callable(PDO, array{items: non-empty-string, playlists: non-empty-string}): void $scenario
 * @param non-empty-string|null $suffix
 */
function example_with_schema(callable $scenario, bool $uniquePosition = false, ?string $suffix = null): void
{
    $pdo = example_connect();
    $suffix ??= bin2hex(random_bytes(6));
    $tables = [
        'items' => 'maa_example_items_' . $suffix,
        'playlists' => 'maa_example_playlists_' . $suffix,
    ];
    $created = [];
    $failure = null;

    try {
        $pdo->exec(
            'CREATE TABLE ' . $tables['playlists'] . ' (
                id BIGINT UNSIGNED NOT NULL COMMENT "Host-owned parent identity.",
                name VARCHAR(64) NOT NULL COMMENT "Host-owned parent name.",
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Temporary example parents."',
        );
        $created[] = $tables['playlists'];

        $pdo->exec(
            'CREATE TABLE ' . $tables['items'] . ' (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT "Row identity.",
                playlist_id BIGINT UNSIGNED NOT NULL COMMENT "Ordering scope.",
                position INT NOT NULL COMMENT "Ordering position inside the scope.",
                title VARCHAR(64) NOT NULL COMMENT "Row title.",
                deleted_at DATETIME NULL DEFAULT NULL COMMENT "Optional soft-delete timestamp.",
                PRIMARY KEY (id),
                ' . ($uniquePosition ? 'UNIQUE ' : '') . 'KEY idx_items_scope_position (playlist_id, position)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Temporary example rows."',
        );
        $created[] = $tables['items'];

        $scenario($pdo, $tables);
    } catch (Throwable $throwable) {
        $failure = $throwable;
    }

    $cleanupFailure = null;

    foreach (ExampleConnections::all() as $connection) {
        try {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        } catch (Throwable $throwable) {
            $cleanupFailure ??= $throwable;
        }
    }

    // Never wait indefinitely for a metadata lock during cleanup.
    try {
        $pdo->exec('SET SESSION lock_wait_timeout = 10');
    } catch (Throwable $throwable) {
        $cleanupFailure ??= $throwable;
    }

    foreach (array_reverse($created) as $table) {
        try {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        } catch (Throwable $throwable) {
            $cleanupFailure ??= $throwable;
        }
    }

    if ($failure !== null) {
        throw $failure;
    }

    if ($cleanupFailure !== null) {
        throw $cleanupFailure;
    }
}

/**
 * Seeds one scope with contiguous positions 1..n (autocommit, own statements).
 *
 * @param list<string> $titles
 */
function example_seed(PDO $pdo, string $table, int $playlistId, array $titles): void
{
    $insert = $pdo->prepare(
        'INSERT INTO ' . $table . ' (playlist_id, position, title) VALUES (:playlist_id, :position, :title)',
    );

    foreach ($titles as $index => $title) {
        $insert->execute(['playlist_id' => $playlistId, 'position' => $index + 1, 'title' => $title]);
    }
}

/**
 * Reads the committed-or-own-visible positions of a scope in ascending order.
 *
 * @return list<int>
 */
function example_positions(PDO $pdo, string $table, int $playlistId): array
{
    $select = $pdo->prepare(
        'SELECT position FROM ' . $table . ' WHERE playlist_id = :playlist_id ORDER BY position ASC, id ASC',
    );
    $select->execute(['playlist_id' => $playlistId]);

    $positions = [];
    foreach ($select->fetchAll(PDO::FETCH_COLUMN) as $value) {
        if (! is_int($value) && ! is_string($value)) {
            throw new RuntimeException('Unexpected position value returned by MySQL.');
        }

        $positions[] = (int) $value;
    }

    return $positions;
}

/**
 * Runs a statement without parameters and returns its first column.
 */
function example_scalar(PDO $pdo, string $sql): mixed
{
    $statement = $pdo->query($sql);

    if (! $statement instanceof PDOStatement) {
        throw new RuntimeException('Unable to run the example query.');
    }

    return $statement->fetchColumn();
}

/**
 * Returns the MySQL driver error code of a PDOException (1205 lock wait
 * timeout, 1213 deadlock, 1062 duplicate key), or null.
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

function example_expect(bool $condition, string $description): void
{
    if (! $condition) {
        throw new RuntimeException('Example expectation failed: ' . $description);
    }
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
