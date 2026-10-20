<?php

declare(strict_types=1);

/**
 * Issue #47 study scaffolding. Probes only; nothing here is package runtime.
 * Env: PERSISTENCE_TEST_MYSQL_DSN/_USER/_PASSWORD (the CI variables) and
 * PROBE_SNAPSHOT_ISOLATION = default | ON | OFF (applied per session when the variable exists).
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;

// PROBE_SRC=<dir containing src/> runs the probes against another source tree (for example the released v1.4.0 tag).
if (is_string(getenv('PROBE_SRC')) && getenv('PROBE_SRC') !== '') {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Maatify\\Persistence\\';
        if (str_starts_with($class, $prefix)) {
            $file = getenv('PROBE_SRC') . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true);
}

const P47_T = 'probe_i47';

function p47_pdo(): PDO
{
    $pdo = new PDO(
        (string) getenv('PERSISTENCE_TEST_MYSQL_DSN'),
        (string) getenv('PERSISTENCE_TEST_MYSQL_USER'),
        (string) getenv('PERSISTENCE_TEST_MYSQL_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );

    $mode = getenv('PROBE_SNAPSHOT_ISOLATION') ?: 'default';
    if ($mode !== 'default') {
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation = ' . $mode);
        } catch (PDOException) {
            // variable absent (MySQL): setting is a no-op for the matrix
        }
    }

    return $pdo;
}

function p47_engine(PDO $pdo): string
{
    try {
        $iso = $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $iso = 'innodb_snapshot_isolation=' . $iso;
    } catch (PDOException) {
        $iso = 'innodb_snapshot_isolation=(absent)';
    }

    return $pdo->query('SELECT VERSION()')->fetchColumn() . ' ' . $iso;
}

function p47_config(): ScopedOrderingConfig
{
    return new ScopedOrderingConfig(P47_T, 'scope_id');
}

function p47_seed(PDO $pdo): void
{
    $pdo->exec('DROP TABLE IF EXISTS ' . P47_T);
    $pdo->exec('CREATE TABLE ' . P47_T . ' (
        id INT AUTO_INCREMENT PRIMARY KEY,
        scope_id INT NOT NULL,
        display_order INT NOT NULL,
        deleted_at DATETIME NULL DEFAULT NULL,
        KEY idx_scope_order (scope_id, display_order)
    ) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO ' . P47_T . ' (scope_id, display_order) VALUES (1,1),(1,2),(1,3),(2,1),(2,2)');
}

/** @return string "ok: <detail>" or "ERR <errno>" */
function p47_try(callable $fn): string
{
    try {
        $r = $fn();

        return 'ok' . ($r === null ? '' : ': ' . (is_scalar($r) ? var_export($r, true) : json_encode($r)));
    } catch (PDOException $e) {
        return 'ERR ' . ($e->errorInfo[1] ?? '?');
    }
}

function p47_state(PDO $pdo): string
{
    return json_encode($pdo->query('SELECT scope_id, display_order FROM ' . P47_T . ' ORDER BY id')->fetchAll(PDO::FETCH_NUM));
}
