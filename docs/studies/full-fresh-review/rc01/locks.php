<?php

declare(strict_types=1);

/** Lock footprint (performance_schema.data_locks) held by an open transaction after one move: id 5 -> 2 on 1..6. */

require __DIR__ . '/lib.php';

$pdo = rc_pdo();
$observer = rc_pdo();

foreach ([['non-unique', false], ['UNIQUE', true]] as [$schemaName, $unique]) {
    foreach (['S0 shipped', 'B  park+ORDER BY', 'B2 park(locked max)+ORDER BY'] as $candidate) {
        if ($unique && $candidate === 'S0 shipped') {
            continue; // fails on UNIQUE (RC-01)
        }

        rc_schema($pdo, $unique, false);
        rc_seed($pdo, [1, 2, 3, 4, 5, 6]);
        $pdo->beginTransaction();
        rc_candidates()[$candidate]($pdo, 5, 2);
        $rows = $observer->query(
            "SELECT index_name, lock_type, lock_mode, COUNT(*) FROM performance_schema.data_locks WHERE object_name = '" . RC_T . "' GROUP BY index_name, lock_type, lock_mode ORDER BY lock_type, index_name, lock_mode",
        )->fetchAll(PDO::FETCH_NUM);
        $pdo->rollBack();
        $total = array_sum(array_map(static fn (array $r): int => (int) $r[3], $rows));
        printf("%-11s %-30s total %2d  %s\n", $schemaName, $candidate, $total, json_encode($rows));
    }
}

$pdo->exec('DROP TABLE IF EXISTS ' . RC_T);
