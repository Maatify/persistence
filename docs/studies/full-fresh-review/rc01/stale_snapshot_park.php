<?php

declare(strict_types=1);

/**
 * Parking pitfall: B computes the parking slot with a plain MAX() (a consistent read). Inside a caller-owned
 * REPEATABLE READ transaction with an older snapshot that value is stale (the same class of defect as Issue #33).
 */

require __DIR__ . '/lib.php';

$pdo = rc_pdo();
$other = rc_pdo();
rc_schema($pdo, true, false);

foreach (['B  park+ORDER BY', 'B2 park(locked max)+ORDER BY'] as $candidate) {
    rc_seed($pdo, [1, 2, 3, 4, 5, 6]);
    $pdo->beginTransaction();
    $pdo->query('SELECT COUNT(*) FROM ' . RC_T)->fetchAll();                       // creates the snapshot
    $other->exec('INSERT INTO ' . RC_T . ' (scope_id, display_order) VALUES (1, 7)'); // committed afterwards
    try {
        rc_candidates()[$candidate]($pdo, 1, 4);
        $outcome = 'ok';
    } catch (Throwable $throwable) {
        $outcome = $throwable instanceof PDOException ? 'PDOException ' . ($throwable->errorInfo[1] ?? '?') . ' ' . $throwable->getMessage() : get_class($throwable);
    }
    $pdo->rollBack();
    printf("%-30s %s\n", $candidate, $outcome);
}

$pdo->exec('DROP TABLE IF EXISTS ' . RC_T);
