<?php

declare(strict_types=1);

/**
 * Shared scaffolding for the exploratory probes (study material, not package API).
 * Connection comes from the same variables as the integration suite:
 * PERSISTENCE_TEST_MYSQL_DSN / _USER / _PASSWORD. Probes create and drop only
 * tables named probe_*; point them at a disposable database.
 */

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function probe_pdo(int $errorMode = PDO::ERRMODE_EXCEPTION, bool $emulatePrepares = false): PDO
{
    $dsn = getenv('PERSISTENCE_TEST_MYSQL_DSN');
    $user = getenv('PERSISTENCE_TEST_MYSQL_USER');
    $password = getenv('PERSISTENCE_TEST_MYSQL_PASSWORD');

    if (!is_string($dsn) || $dsn === '' || !is_string($user) || !is_string($password)) {
        fwrite(STDERR, "Set PERSISTENCE_TEST_MYSQL_DSN, _USER and _PASSWORD.\n");

        exit(2);
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => $errorMode,
        PDO::ATTR_EMULATE_PREPARES => $emulatePrepares,
    ]);
}

function probe_try(string $name, callable $callback): void
{
    echo '[' . $name . '] ';

    try {
        echo json_encode($callback(), JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $throwable) {
        echo get_class($throwable) . ': ' . $throwable->getMessage() . "\n";
    }
}
