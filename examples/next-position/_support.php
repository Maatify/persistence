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

    return $pdo;
}

/**
 * Creates uniquely named example tables, runs the scenario, and always drops
 * them again, so examples never touch host tables and can be re-run.
 *
 * `items` holds ordered rows (scope column `playlist_id`, order column
 * `position`, optional soft delete). `playlists` is the parent table whose rows
 * a host can lock to serialize the first insert into an empty scope.
 *
 * @param callable(PDO, array{items: non-empty-string, playlists: non-empty-string}): void $scenario
 */
function example_with_schema(callable $scenario, bool $uniquePosition = false): void
{
    $pdo = example_connect();
    $suffix = bin2hex(random_bytes(6));
    $tables = [
        'items' => 'maa_example_items_' . $suffix,
        'playlists' => 'maa_example_playlists_' . $suffix,
    ];

    $pdo->exec(
        'CREATE TABLE ' . $tables['playlists'] . ' (
            id BIGINT UNSIGNED NOT NULL COMMENT "Host-owned parent identity.",
            name VARCHAR(64) NOT NULL COMMENT "Host-owned parent name.",
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Temporary example parents."',
    );
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

    try {
        $scenario($pdo, $tables);
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $pdo->exec('DROP TABLE IF EXISTS ' . $tables['items']);
        $pdo->exec('DROP TABLE IF EXISTS ' . $tables['playlists']);
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
