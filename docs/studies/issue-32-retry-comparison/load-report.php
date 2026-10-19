<?php

/**
 * Maps the host load-average samples (host-load.sh) onto the runs of one results directory and flags runs that
 * overlapped a busy host (load average well above what the study itself can generate). Usage: php load-report.php <results-dir>   Writes LOAD.md and load.json.
 */

declare(strict_types=1);

const NOISY_EXCESS_LOAD = 6.0;   // load1 above (connections + 2) + 6 means foreign work is competing for the 8 vCPUs

$dir = rtrim($argv[1] ?? throw new InvalidArgumentException('results dir required'), '/');
$samples = [];
foreach (file($dir . '/host-load.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    [$ts, $load] = explode(' ', $line);
    $samples[] = [strtotime($ts), (float) $load];
}
$rows = [];
foreach (glob($dir . '/runs/*.jsonl.gz') ?: [] as $file) {
    $gz = gzopen($file, 'rb');
    $last = '';
    while (($l = gzgets($gz)) !== false) {
        $last = $l;
    }
    gzclose($gz);
    $s = json_decode($last, true);
    $end = strtotime($s['started_at']);   // written when the run finished
    $start = $end - (int) ceil($s['wall_us'] / 1e6) - 2;
    $in = array_filter($samples, static fn(array $x): bool => $x[0] >= $start && $x[0] <= $end + 1);
    $f = array_column($in, 1);
    $allowed = $s['connections'] + 2 + NOISY_EXCESS_LOAD;
    $rows[basename($file, '.jsonl.gz')] = [
        'samples' => count($f), 'load1_mean' => $f ? array_sum($f) / count($f) : null, 'load1_max' => $f ? max($f) : null, 'load1_allowed' => $allowed,
        'noisy' => $f === [] || max($f) > $allowed,
        'tag' => $s['tag'], 'connections' => $s['connections'], 'round' => $s['round'], 'policy' => $s['policy'],
    ];
}
ksort($rows);
$noisy = array_filter($rows, static fn(array $r): bool => $r['noisy']);
$md = ["# Host load during the runs\n", sprintf('A run is flagged noisy when the host 1-minute load average during it exceeded connections + 2 + %d (the study cannot generate more runnable threads than that on its own), or when it has no samples. Host: 8 vCPUs. %d of %d runs flagged. (The 1-minute average lags, so a flag can also trail a short burst.)', NOISY_EXCESS_LOAD, count($noisy), count($rows)), ''];
if ($noisy !== []) {
    $md[] = '| run | load1 mean | load1 max | allowed |';
    $md[] = '|---|---:|---:|---:|';
    foreach ($noisy as $name => $r) {
        $md[] = sprintf('| %s | %s | %s | %s |', $name, $r['load1_mean'] === null ? 'n/a' : round($r['load1_mean'], 1), $r['load1_max'] === null ? 'n/a' : round($r['load1_max'], 1), $r['load1_allowed']);
    }
}
file_put_contents($dir . '/LOAD.md', implode("\n", $md) . "\n");
file_put_contents($dir . '/load.json', json_encode(['noisy_runs' => array_keys($noisy), 'runs' => $rows], JSON_PRETTY_PRINT));
echo implode("\n", $md), "\n";
