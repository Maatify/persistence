<?php

/**
 * Issue #42 independent re-check of the raw per-operation files, deliberately NOT using analyze.php or lib.php:
 * every operation ends ok/exhausted; at most 4 attempts; every retried attempt failed with 1213 AND SQLSTATE 40001;
 * Immediate never sleeps; Hybrid sleeps within [0,0] / [0,10 ms] / [0,20 ms] before retries 1 / 2 / 3;
 * the deadlocks counted from the raw attempts equal both the summary's client counter and the server delta.
 * Usage: php raw-recheck.php <results-dir> [...]   (exit 1 if any check fails)
 */

declare(strict_types=1);

$failed = false;
foreach (array_slice($argv, 1) as $dir) {
    $t = ['runs' => 0, 'ops' => 0, 'bad_outcome' => 0, 'over_4_attempts' => 0, 'retry_without_1213_40001' => 0,
        'immediate_sleep_nonzero' => 0, 'hybrid_sleep_out_of_bounds' => 0, 'server_client_deadlock_mismatch' => 0];
    foreach (glob($dir . '/runs/*.jsonl.gz') ?: [] as $file) {
        $hybrid = str_contains(basename($file), '_hybrid');
        $deadlocks = 0;
        $summary = null;
        foreach (gzfile($file) ?: [] as $line) {
            $o = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if ($o['type'] === 'summary') {
                $summary = $o;
                continue;
            }
            ++$t['ops'];
            $t['bad_outcome'] += in_array($o['outcome'], ['ok', 'exhausted'], true) ? 0 : 1;
            $attempts = $o['attempts'];
            $t['over_4_attempts'] += count($attempts) > 4 ? 1 : 0;
            foreach ($attempts as $i => [, , $code, $sleep, $state]) {
                $deadlocks += $code === 1213 ? 1 : 0;
                if ($i === count($attempts) - 1) {
                    continue;
                }
                $t['retry_without_1213_40001'] += ($code === 1213 && $state === '40001') ? 0 : 1;
                if ($hybrid) {
                    $t['hybrid_sleep_out_of_bounds'] += ($sleep >= 0 && $sleep <= [0, 10000, 20000][$i]) ? 0 : 1;
                } else {
                    $t['immediate_sleep_nonzero'] += $sleep === 0 ? 0 : 1;
                }
            }
        }
        ++$t['runs'];
        // Raw attempts vs the run summary's client counter vs the MySQL lock_deadlocks delta; a missing field fails.
        $match = $summary !== null && ($summary['server_deadlocks_delta'] ?? null) === $deadlocks
            && ($summary['client_deadlocks'] ?? null) === $deadlocks;
        $t['server_client_deadlock_mismatch'] += $match ? 0 : 1;
    }
    $ok = $t['runs'] > 0 && array_sum(array_slice($t, 2)) === 0;
    $failed = $failed || !$ok;
    echo ($ok ? 'PASS ' : 'FAIL '), basename($dir), ' ', json_encode($t), "\n";
}
exit($failed ? 1 : 0);
