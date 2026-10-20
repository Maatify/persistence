<?php

/**
 * Host-condition classification shared by load-report.php (writes load.json) and analyze.php (re-computes it from the
 * raw host-load.log and the run summaries, so that load.json can never be trusted blindly).
 *
 * Log formats: "<ISO> foreign=<cpu> load1=<x> v=2" (current), "<ISO> foreign=<cpu> load1=<x>" (v1, superseded
 * attribution by process NAME), "<ISO> <load1>" (run-3 only; load average).
 */

declare(strict_types=1);

const LOAD_NOISY_MEAN_CPU = 200.0;   // mean foreign CPU above two cores (25 % of the 8 vCPUs)
const LOAD_NOISY_PEAK_CPU = 400.0;
const LOAD_NOISY_EXCESS_LOAD = 6.0;  // legacy load-average rule: load1 > connections + 2 + 6

/**
 * @return array{format:string,attribution:string,rule:string,runs:array<string,array>,noisy_runs:list<string>}
 */
function computeLoadReport(string $dir): array
{
    $samples = [];
    $format = 'none';
    $attribution = 'none';
    foreach (is_file($dir . '/host-load.log') ? (file($dir . '/host-load.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
        if (preg_match('/^(\S+) foreign=([\d.]+) load1=([\d.]+) v=2$/', $line, $m) === 1) {
            $format = 'cpu';
            $attribution = 'process-tree-v2';
            $samples[] = [strtotime($m[1]), (float) $m[2]];
        } elseif (preg_match('/^(\S+) foreign=([\d.]+) load1=([\d.]+)$/', $line, $m) === 1) {
            $format = 'cpu';
            $attribution = 'process-name-v1';
            $samples[] = [strtotime($m[1]), (float) $m[2]];
        } elseif (preg_match('/^(\S+) ([\d.]+)$/', $line, $m) === 1) {
            $format = 'load1';
            $attribution = 'load-average';
            $samples[] = [strtotime($m[1]), (float) $m[2]];
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
        $slack = $format === 'cpu' ? 2 : 1;
        $f = array_column(array_filter($samples, static fn(array $x): bool => $x[0] >= $start && $x[0] <= $end + $slack), 1);
        $noisy = $f === [];
        if ($f !== []) {
            $noisy = $format === 'cpu'
                ? (array_sum($f) / count($f) > LOAD_NOISY_MEAN_CPU || max($f) > LOAD_NOISY_PEAK_CPU)
                : max($f) > $s['connections'] + 2 + LOAD_NOISY_EXCESS_LOAD;
        }
        $rows[basename($file, '.jsonl.gz')] = [
            'samples' => count($f), 'mean' => $f ? array_sum($f) / count($f) : null, 'max' => $f ? max($f) : null,
            'noisy' => $noisy, 'tag' => $s['tag'], 'connections' => $s['connections'], 'round' => $s['round'], 'policy' => $s['policy'],
        ];
    }
    ksort($rows);
    $rule = $format === 'cpu'
        ? sprintf('mean foreign CPU above %d%% of one core (%d%% of the 8 vCPUs) or peak above %d%%, or no samples', LOAD_NOISY_MEAN_CPU, LOAD_NOISY_MEAN_CPU / 8, LOAD_NOISY_PEAK_CPU)
        : sprintf('host 1-minute load average above connections + 2 + %d, or no samples (legacy run-3 sampling)', LOAD_NOISY_EXCESS_LOAD);

    return ['format' => $format, 'attribution' => $attribution, 'rule' => $rule, 'runs' => $rows, 'noisy_runs' => array_keys(array_filter($rows, static fn(array $r): bool => $r['noisy']))];
}
