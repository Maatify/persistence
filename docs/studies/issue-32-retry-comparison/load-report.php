<?php

/**
 * Writes LOAD.md and load.json for one results directory (classification: harness/loadlib.php, which analyze.php
 * re-computes independently). Usage: php load-report.php <results-dir>
 */

declare(strict_types=1);

require __DIR__ . '/harness/loadlib.php';

$dir = rtrim($argv[1] ?? throw new InvalidArgumentException('results dir required'), '/');
$r = computeLoadReport($dir);
$md = ["# Host conditions during the runs\n", sprintf('Log format: `%s` (attribution: `%s`). A run is flagged noisy when: %s. %d of %d runs flagged.', $r['format'], $r['attribution'], $r['rule'], count($r['noisy_runs']), count($r['runs'])), ''];
if ($r['noisy_runs'] !== []) {
    $md[] = '| run | samples | mean | max |';
    $md[] = '|---|---:|---:|---:|';
    foreach ($r['noisy_runs'] as $name) {
        $x = $r['runs'][$name];
        $md[] = sprintf('| %s | %d | %s | %s |', $name, $x['samples'], $x['mean'] === null ? 'n/a' : round($x['mean'], 1), $x['max'] === null ? 'n/a' : round($x['max'], 1));
    }
}
file_put_contents($dir . '/LOAD.md', implode("\n", $md) . "\n");
file_put_contents($dir . '/load.json', json_encode($r, JSON_PRETTY_PRINT));
echo implode("\n", $md), "\n";
