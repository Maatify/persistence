<?php

declare(strict_types=1);

/**
 * Portability probe: REPEATABLE READ locking reads against a snapshot older than another connection's commit
 * (the Issue #33 scenario). Reports the engine version, innodb_snapshot_isolation when it exists, and what the
 * SHIPPED moveWithinScope() / getNextPositionForUpdate() do.
 */

require __DIR__ . '/lib.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

$pdo = rc_pdo();
$other = rc_pdo();
echo 'engine: ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";

try {
    echo 'innodb_snapshot_isolation = ' . $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn() . "\n";
} catch (PDOException) {
    echo "innodb_snapshot_isolation: variable does not exist\n";
}

foreach ([null, 'OFF'] as $setting) {
    if ($setting !== null) {
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation = ' . $setting);
        } catch (PDOException) {
            continue;
        }
    }

    foreach (['moveWithinScope' => static fn (PDO $c): mixed => (new ScopedOrderingManager())->moveWithinScope($c, rc_config(), 1, 1, 5),
        'getNextPositionForUpdate' => static fn (PDO $c): mixed => (new ScopedOrderingManager())->getNextPositionForUpdate($c, rc_config(), 1)] as $name => $call) {
        rc_schema($pdo, false, false);
        rc_seed($pdo, [1, 2, 3]);
        $pdo->beginTransaction();
        $pdo->query('SELECT COUNT(*) FROM ' . RC_T)->fetchAll();
        $other->exec('INSERT INTO ' . RC_T . ' (scope_id, display_order) VALUES (1, 4), (1, 5)');

        try {
            $result = $call($pdo);
            echo sprintf("  snapshot_isolation=%-8s %-26s -> %s, rows %s\n", $setting ?? '(default)', $name, var_export($result, true), json_encode(rc_state($pdo)));
        } catch (PDOException $exception) {
            echo sprintf("  snapshot_isolation=%-8s %-26s -> PDOException %s\n", $setting ?? '(default)', $name, ($exception->errorInfo[1] ?? '?') . ' ' . $exception->getMessage());
        }

        $pdo->rollBack();
    }
}

$pdo->exec('DROP TABLE IF EXISTS ' . RC_T);
