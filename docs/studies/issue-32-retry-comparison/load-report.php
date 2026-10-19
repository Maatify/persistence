<?php

/**
 * Maps the host samples (host-load.sh) onto the runs of one results directory and flags runs that overlapped
 * foreign work on the host. Usage: php load-report.php <results-dir>   Writes LOAD.md and load.json.
 *
 * Log formats: "<ISO> foreign=<cpu% of one core> load1=<x>" (current; CPU used by processes outside the study) or
 * "<ISO> <load1>" (run-3 only; 1-minute load average, flagged against connections + 2 + 6).
 */

declare(strict_types=1);

const NOISY_MEAN_CPU = 200.0;   // mean foreign CPU above two cores (25 % of the 8 vCPUs)
const NOISY_PEAK_CPU = 400.0;
const NOISY_EXCESS_LOAD = 6.0;

$dir = rtrim($argv[1] ?? throw new InvalidArgumentException('results dir required'), '/');
$samples = [];
$format = 'none';
foreach (file($dir . '/host-load.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (preg_match('/^(\S+) foreign=([\d.]+) load1=([\d.]+)$/', $line, $m) === 1) {
        $format = 'cpu';
        $samples[] = [strtotime($m[1]), (float) $m[2], (float) $m[3]];
    } elseif (preg_match('/^(\S+) ([\d.]+)$/', $line, $m) === 1) {
        $format = 'load1';
        $samples[] = [strtotime($m[1]), (float) $m[2], (float) $m[2]];
    }
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
    $in = array_filter($samples, static fn(array $x): bool => $x[0] >= $start && $x[0] <= $end + 2);
    $f = array_column($in, 1);
    $noisy = $f === [];
    if ($f !== []) {
        $noisy = $format === 'cpu'
            ? (array_sum($f) / count($f) > NOISY_MEAN_CPU || max($f) > NOISY_PEAK_CPU)
            : max($f) > $s['connections'] + 2 + NOISY_EXCESS_LOAD;
    }
    $rows[basename($file, '.jsonl.gz')] = [
        'samples' => count($f), 'mean' => $f ? array_sum($f) / count($f) : null, 'max' => $f ? max($f) : null,
        'noisy' => $noisy, 'tag' => $s['tag'], 'connections' => $s['connections'], 'round' => $s['round'], 'policy' => $s['policy'],
    ];
}
ksort($rows);
$noisy = array_filter($rows, static fn(array $r): bool => $r['noisy']);
$rule = $format === 'cpu'
    ? sprintf('mean foreign CPU above %d%% of one core (%d%% of the 8 vCPUs) or peak above %d%%, or no samples', NOISY_MEAN_CPU, NOISY_MEAN_CPU / 8, NOISY_PEAK_CPU)
    : sprintf('host 1-minute load average above connections + 2 + %d, or no samples (legacy run-3 sampling)', NOISY_EXCESS_LOAD);
$md = ["# Host conditions during the runs\n", sprintf('Log format: `%s`. A run is flagged noisy when: %s. %d of %d runs flagged.', $format, $rule, count($noisy), count($rows)), ''];
if ($noisy !== []) {
    $md[] = '| run | mean | max |';
    $md[] = '|---|---:|---:|';
    foreach ($noisy as $name => $r) {
        $md[] = sprintf('| %s | %s | %s |', $name, $r['mean'] === null ? 'n/a' : round($r['mean'], 1), $r['max'] === null ? 'n/a' : round($r['max'], 1));
    }
}
file_put_contents($dir . '/LOAD.md', implode("\n", $md) . "\n");
file_put_contents($dir . '/load.json', json_encode(['format' => $format, 'rule' => $rule, 'noisy_runs' => array_keys($noisy), 'runs' => $rows], JSON_PRETTY_PRINT));
echo implode("\n", $md), "\n";
