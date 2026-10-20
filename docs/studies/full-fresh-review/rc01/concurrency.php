<?php

declare(strict_types=1);

/**
 * Concurrency: N worker processes issue random moves (owned transactions) on ONE scope of a UNIQUE table with
 * candidate B2, while a mixed workload checks the invariant at the end: active orders are exactly 1..n, no errors
 * other than the documented lock errors (1213/1205), which a real caller would retry.
 * Usage: php concurrency.php [workers=4] [moves=150] [candidate] [unique|nonunique]
 */

require __DIR__ . '/lib.php';

if (($argv[1] ?? '') === '--worker') {
    $candidate = $argv[2];
    $moves = (int) $argv[3];
    $seed = (int) $argv[4];
    mt_srand($seed);
    $pdo = rc_pdo();
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');
    $ok = 0;
    $errors = [];

    for ($i = 0; $i < $moves; $i++) {
        try {
            rc_candidates()[$candidate]($pdo, mt_rand(1, 8), mt_rand(1, 9));
            $ok++;
        } catch (PDOException $exception) {
            $code = (string) ($exception->errorInfo[1] ?? '?');
            $errors[$code] = ($errors[$code] ?? 0) + 1;

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    echo json_encode(['ok' => $ok, 'errors' => $errors]), "\n";

    exit(0);
}

$workers = (int) ($argv[1] ?? 4);
$moves = (int) ($argv[2] ?? 150);
$candidate = $argv[3] ?? 'B2 park(locked max)+ORDER BY';
$pdo = rc_pdo();
$unique = ($argv[4] ?? 'unique') === 'unique';
rc_schema($pdo, $unique, false);
rc_seed($pdo, [1, 2, 3, 4, 5, 6, 7, 8]);

$processes = [];

for ($w = 0; $w < $workers; $w++) {
    $processes[] = proc_open([PHP_BINARY, __FILE__, '--worker', $candidate, (string) $moves, (string) (1000 + $w)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$w]);
}

$summary = [];

foreach ($processes as $w => $process) {
    $summary[] = trim((string) stream_get_contents($pipes[$w][1])) . trim((string) stream_get_contents($pipes[$w][2]));
    proc_close($process);
}

$orders = array_values(rc_state($pdo));
sort($orders);
echo 'schema: ' . ($unique ? 'UNIQUE' : 'non-unique') . "\n";
echo "candidate: $candidate; workers: $workers x $moves moves\n";
echo implode("\n", $summary), "\n";
echo 'final active orders: ', json_encode($orders), "\n";
echo 'INVARIANT (exactly 1..8, no duplicates, no gaps): ', $orders === range(1, 8) ? 'HOLDS' : 'VIOLATED', "\n";
$pdo->exec('DROP TABLE IF EXISTS ' . RC_T);
