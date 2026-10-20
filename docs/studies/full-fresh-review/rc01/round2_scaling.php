<?php

declare(strict_types=1);

/**
 * Round 2 / F45-R04: lock counts and latency versus scope cardinality and isolation level.
 * Move of the row at position n-1 to position 2 (shifts n-3 rows). Needs SELECT on performance_schema.data_locks (MySQL).
 * Usage: php round2_scaling.php [sizes=6,100,1000,10000] [latencySizes=1000,10000,100000]
 */

require __DIR__ . '/lib.php';

$pdo = rc_pdo();
$observer = rc_pdo();
$sizes = array_map('intval', explode(',', $argv[1] ?? '6,100,1000,10000'));
$latencySizes = array_map('intval', explode(',', $argv[2] ?? '1000,10000,100000'));
$pdo->exec('SET SESSION cte_max_recursion_depth = 1000000');

function fill(PDO $pdo, int $n): void
{
    $pdo->exec('TRUNCATE TABLE ' . RC_T);
    $pdo->exec('INSERT INTO ' . RC_T . ' (scope_id, display_order) WITH RECURSIVE s (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < ' . $n . ') SELECT 1, n FROM s');
}

$variants = [
    ['non-unique', 'S0 shipped', false],
    ['non-unique', 'B3 park(locked max, all rows)+ORDER BY', false],
    ['UNIQUE', 'B2 park(locked max)+ORDER BY', true],
    ['UNIQUE', 'B3 park(locked max, all rows)+ORDER BY', true],
];

echo "== lock counts held by the open transaction after one move (total / by mode)\n";
foreach (['REPEATABLE READ', 'READ COMMITTED'] as $isolation) {
    foreach ($sizes as $n) {
        foreach ($variants as [$schema, $candidate, $unique]) {
            rc_schema($pdo, $unique, false);
            fill($pdo, $n);
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
            $pdo->beginTransaction();
            rc_candidates()[$candidate]($pdo, $n - 1, 2);
            $rows = $observer->query("SELECT lock_mode, COUNT(*) FROM performance_schema.data_locks WHERE object_name = '" . RC_T . "' AND lock_type = 'RECORD' GROUP BY lock_mode ORDER BY lock_mode")->fetchAll(PDO::FETCH_NUM);
            $pdo->rollBack();
            $total = array_sum(array_map(static fn (array $r): int => (int) $r[1], $rows));
            printf("%-15s n=%-6d %-11s %-40s total %-6d %s\n", $isolation, $n, $schema, $candidate, $total, json_encode($rows));
        }
    }
}

$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
echo "\n== latency (ms), median of 7 owned-transaction moves, single connection, row n-1 -> 2\n";
foreach ($latencySizes as $n) {
    foreach ($variants as [$schema, $candidate, $unique]) {
        rc_schema($pdo, $unique, false);
        fill($pdo, $n);
        $samples = [];

        for ($i = 0; $i < 7; $i++) {
            $start = hrtime(true);
            rc_candidates()[$candidate]($pdo, $n - 1, 2);
            $samples[] = (hrtime(true) - $start) / 1e6;
            rc_candidates()[$candidate]($pdo, 2, $n - 1); // restore roughly; not measured
        }

        sort($samples);
        printf("n=%-7d %-11s %-40s median %8.1f ms  (min %.1f, max %.1f)\n", $n, $schema, $candidate, $samples[3], $samples[0], $samples[6]);
    }
}

$pdo->exec('DROP TABLE IF EXISTS ' . RC_T);
