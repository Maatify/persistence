<?php

declare(strict_types=1);

/**
 * Round 2 / F45-R01: a plain writer inserting at the parking slot between the locking read of the top row and the
 * parking write. REPEATABLE READ (gap lock above the top record) versus READ COMMITTED (no gap lock).
 */

require __DIR__ . '/lib.php';

$pdo = rc_pdo();
$writer = rc_pdo();
$writer->exec('SET SESSION innodb_lock_wait_timeout = 1');

foreach (['REPEATABLE READ', 'READ COMMITTED'] as $isolation) {
    rc_schema($pdo, true, false);
    rc_seed($pdo, [1, 2, 3, 4, 5, 6]);
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
    $pdo->beginTransaction();
    rc_lock_scope($pdo);
    $top = (int) $pdo->query('SELECT display_order FROM ' . RC_T . ' WHERE scope_id = 1 ORDER BY display_order DESC, id DESC LIMIT 1 FOR UPDATE')->fetchColumn();
    echo "== $isolation: move transaction holds the top row ($top); a plain writer inserts at " . ($top + 1) . "\n";

    try {
        $writer->exec('INSERT INTO ' . RC_T . ' (scope_id, display_order) VALUES (1, ' . ($top + 1) . ')');
        echo "  writer: inserted\n";
    } catch (PDOException $exception) {
        echo '  writer: error ' . ($exception->errorInfo[1] ?? '?') . "\n";
    }

    try {
        rc_set($pdo, 1, $top + 1);
        echo "  mover : parking write succeeded\n";
    } catch (PDOException $exception) {
        echo '  mover : parking write error ' . ($exception->errorInfo[1] ?? '?') . " (clean error, nothing parked)\n";
    }

    $pdo->rollBack();
    echo '  state after: ' . json_encode(rc_state($pdo)) . "\n";
}

$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->exec('DROP TABLE IF EXISTS ' . RC_T);
