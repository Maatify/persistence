<?php

/**
 * Issue #32 study harness: analysis. Reads results/<dir>/runs/*.jsonl.gz and prints / writes the metrics and the
 * pre-registered decision (see ../METHODOLOGY.md §6; thresholds are constants below, fixed BEFORE the main runs).
 *
 *   php analyze.php --dir=../results/main [--decision-set=sequential-wave --decision-level=8]
 *
 * Writes <dir>/analysis.json and <dir>/ANALYSIS.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/loadlib.php';

// ---- pre-registered decision constants -------------------------------------------------------------------------
const MIN_ROUNDS = 5;
const MIN_OPS_PER_ROUND = 200;
const MIN_IMMEDIATE_EXHAUSTED_POOLED = 30;   // below this the exhaustion criterion cannot be tested reliably
const C1_REL = 0.20;                        // exhausted reduction, relative
const C1_ABS_PP = 1.0;                      // exhausted reduction, absolute percentage points
const C1_ROUND_FRACTION = 0.8;              // 4 of 5 rounds, scaled: ceil(0.8 * rounds)
const C2_MAX_THROUGHPUT_DROP = 0.05;
const C3_REL = 0.10;                        // P95 increase allowance: max(10 %, 10 ms)
const C3_ABS_US = 10_000;

function args(array $argv): array
{
    $a = [];
    foreach (array_slice($argv, 1) as $x) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $x, $m) === 1) {
            $a[$m[1]] = $m[2];
        }
    }

    return $a;
}

/** Nearest-rank percentile (p in 0..1) over an unsorted list of numbers. */
function pct(array $values, float $p): ?float
{
    $n = count($values);
    if ($n === 0) {
        return null;
    }
    sort($values);

    return (float) $values[max(0, (int) ceil($p * $n) - 1)];
}

/** Wilson score interval. */
function wilson(int $x, int $n, float $z = 1.959964): array
{
    if ($n === 0) {
        return [0.0, 0.0];
    }
    $p = $x / $n;
    $d = 1 + $z * $z / $n;
    $c = ($p + $z * $z / (2 * $n)) / $d;
    $h = $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n)) / $d;

    return [$c - $h, $c + $h];
}

/** Newcombe hybrid-score 95 % CI for p1 - p2. */
function newcombe(int $x1, int $n1, int $x2, int $n2): array
{
    $p1 = $n1 ? $x1 / $n1 : 0;
    $p2 = $n2 ? $x2 / $n2 : 0;
    [$l1, $u1] = wilson($x1, $n1);
    [$l2, $u2] = wilson($x2, $n2);

    return [
        $p1 - $p2 - sqrt(($p1 - $l1) ** 2 + ($u2 - $p2) ** 2),
        $p1 - $p2 + sqrt(($u1 - $p1) ** 2 + ($p2 - $l2) ** 2),
    ];
}

/** @return array{ops:list<array>,summary:array} */
function readRun(string $file): array
{
    $ops = [];
    $summary = null;
    $gz = gzopen($file, 'rb');
    while (($line = gzgets($gz)) !== false) {
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if ($row['type'] === 'op') {
            $ops[] = $row;
        } else {
            $summary = $row;
        }
    }
    gzclose($gz);

    return ['ops' => $ops, 'summary' => $summary];
}

/** Metrics of one run (one policy, one round). */
/** An attempt is a deadlock only with driver code 1213 AND SQLSTATE 40001 (legacy run files carry no SQLSTATE). */
function isDeadlockAttempt(array $attempt): bool
{
    if ($attempt[2] !== 1213) {
        return false;
    }

    return !array_key_exists(4, $attempt) || $attempt[4] === '40001';
}

function runMetrics(array $run): array
{
    $s = $run['summary'];
    $ops = $run['ops'];
    $attemptsTotal = 0;
    $original = 0;
    $repeated = 0;
    $lat = [];
    $latOk = [];
    $hist = [];
    $sleepTotal = 0;
    $violations = [];
    $policy = $s['policy'];
    $bounds = ['immediate' => [0, 0, 0], 'hybrid' => [0, 10_000, 20_000], 'none' => []][$policy];
    foreach ($ops as $op) {
        $a = $op['attempts'];
        $attemptsTotal += count($a);
        foreach ($a as $i => $attemptRow) {
            [$start, $dur, $code, $sleep] = $attemptRow;
            $isDeadlock = isDeadlockAttempt($attemptRow);
            if ($isDeadlock) {
                $i === 0 ? ++$original : ++$repeated;
            }
            $sleepTotal += $sleep;
            if ($i > 0) {
                [$ps, $pd, , $psl] = $a[$i - 1];
                if ($start < $ps + $pd + $psl) {
                    $violations[] = $op['op'] . ': attempt ' . ($i + 1) . ' started before the previous attempt ended (+sleep)';
                }
                if (!isDeadlockAttempt($a[$i - 1])) {
                    $violations[] = $op['op'] . ': retried after a non-deadlock (1213 + SQLSTATE 40001) outcome';
                }
            }
            if ($sleep > ($bounds[$i] ?? 0)) {
                $violations[] = $op['op'] . ': sleep ' . $sleep . 'us exceeds policy bound before retry ' . ($i + 1);
            }
        }
        if ($op['outcome'] === 'invalid') {
            $violations[] = $op['op'] . ': ended as invalid (transaction ownership/cleanup precondition failed)';
        }
        if ($op['outcome'] === 'exhausted' && ($a === [] || !isDeadlockAttempt(end($a)) || count($a) !== count($bounds) + 1)) {
            $violations[] = $op['op'] . ': exhausted without four deadlock attempts';
        }
        if ($op['outcome'] === 'ok' && end($a)[2] !== 0) {
            $violations[] = $op['op'] . ': ok without a successful final attempt';
        }
        if (count($a) > count($bounds) + 1) {
            $violations[] = $op['op'] . ': more than ' . (count($bounds) + 1) . ' attempts';
        }
        $lat[] = $op['latency_us'];
        if ($op['outcome'] === 'ok') {
            $latOk[] = $op['latency_us'];
            $hist[count($a)] = ($hist[count($a)] ?? 0) + 1;
        }
    }
    ksort($hist);
    $n = count($ops);
    $active = $s['active_us'] / 1e6;
    $i = $s['integrity'];
    $integrityPass = $i['rows_match_ok_ops'] && $i['duplicate_product_rows'] === 0 && $i['name_mismatch_rows'] === 0
        && $i['callback_runs_ne_attempts'] === 0 && $i['server_open_transactions_after_run'] === 0 && $i['flags'] === []
        && $i['other_errors'] === [] && $i['all_isolation_repeatable_read'] && $s['errors'] === 0 && ($s['invalid'] ?? 0) === 0
        && $s['client_deadlocks'] === $s['server_deadlocks_delta'] && $violations === [];

    return [
        'policy' => $policy, 'round' => $s['round'], 'order_in_round' => $s['order_in_round'],
        'ops' => $n, 'ok' => $s['ok'], 'exhausted' => $s['exhausted'], 'errors' => $s['errors'],
        'success_rate' => $n ? $s['ok'] / $n : null, 'exhausted_rate' => $n ? $s['exhausted'] / $n : null,
        'attempts_total' => $attemptsTotal, 'attempts_per_op' => $n ? $attemptsTotal / $n : null,
        'deadlocks_original' => $original, 'deadlocks_repeated' => $repeated,
        'deadlocks_client' => $s['client_deadlocks'], 'deadlocks_server' => $s['server_deadlocks_delta'],
        'original_deadlock_rate' => $n ? $original / $n : null,
        'active_s' => $active, 'wall_s' => $s['wall_us'] / 1e6,
        'goodput_ops_s' => $active > 0 ? $s['ok'] / $active : null,
        'completed_ops_s' => $active > 0 ? $n / $active : null,
        'lat_all_us' => ['p50' => pct($lat, .5), 'p95' => pct($lat, .95), 'p99' => count($lat) >= 100 ? pct($lat, .99) : null, 'max' => $lat ? max($lat) : null],
        'lat_ok_us' => ['p50' => pct($latOk, .5), 'p95' => pct($latOk, .95), 'p99' => count($latOk) >= 100 ? pct($latOk, .99) : null],
        'ok_by_attempts' => $hist, 'sleep_total_ms' => $sleepTotal / 1000,
        'integrity_pass' => $integrityPass, 'violations' => array_slice($violations, 0, 5),
        '_lat_all' => $lat, '_lat_ok' => $latOk,
    ];
}

/** Pooled aggregate of the runs of one policy. */
function pool(array $runs): array
{
    $sum = static fn(string $k): float|int => array_sum(array_column($runs, $k));
    $lat = array_merge(...array_column($runs, '_lat_all'));
    $latOk = array_merge(...array_column($runs, '_lat_ok'));
    $ops = (int) $sum('ops');
    $active = $sum('active_s');
    $hist = [];
    foreach ($runs as $r) {
        foreach ($r['ok_by_attempts'] as $k => $v) {
            $hist[$k] = ($hist[$k] ?? 0) + $v;
        }
    }
    ksort($hist);

    return [
        'rounds' => count($runs), 'ops' => $ops, 'ok' => (int) $sum('ok'), 'exhausted' => (int) $sum('exhausted'), 'errors' => (int) $sum('errors'),
        'exhausted_rate' => $ops ? $sum('exhausted') / $ops : null, 'success_rate' => $ops ? $sum('ok') / $ops : null,
        'attempts_total' => (int) $sum('attempts_total'), 'attempts_per_op' => $ops ? $sum('attempts_total') / $ops : null,
        'deadlocks_original' => (int) $sum('deadlocks_original'), 'deadlocks_repeated' => (int) $sum('deadlocks_repeated'),
        'original_deadlock_rate' => $ops ? $sum('deadlocks_original') / $ops : null,
        'active_s' => $active, 'goodput_ops_s' => $active ? $sum('ok') / $active : null, 'completed_ops_s' => $active ? $ops / $active : null,
        'lat_all_us' => ['p50' => pct($lat, .5), 'p95' => pct($lat, .95), 'p99' => pct($lat, .99), 'mean' => $lat ? array_sum($lat) / count($lat) : null],
        'lat_ok_us' => ['p50' => pct($latOk, .5), 'p95' => pct($latOk, .95), 'p99' => pct($latOk, .99)],
        'ok_by_attempts' => $hist, 'sleep_total_ms' => $sum('sleep_total_ms'),
        'integrity_pass_all_runs' => !in_array(false, array_column($runs, 'integrity_pass'), true),
        'exhausted_wilson95' => wilson((int) $sum('exhausted'), $ops),
    ];
}

/** Pre-registered comparison of Hybrid against Immediate for one (tag, level) cell. */
function compare(array $imm, array $hyb): array
{
    $rounds = array_keys($imm);
    $roundRows = [];
    $wins = 0;
    foreach ($rounds as $round) {
        $i = $imm[$round];
        $h = $hyb[$round] ?? null;
        if ($h === null) {
            continue;
        }
        $abs = ($i['exhausted_rate'] - $h['exhausted_rate']) * 100;
        $rel = $i['exhausted_rate'] > 0 ? ($i['exhausted_rate'] - $h['exhausted_rate']) / $i['exhausted_rate'] : 0.0;
        $win = $rel >= C1_REL && $abs >= C1_ABS_PP;
        $wins += $win ? 1 : 0;
        $roundRows[] = [
            'round' => $round, 'immediate_exhausted_pct' => $i['exhausted_rate'] * 100, 'hybrid_exhausted_pct' => $h['exhausted_rate'] * 100,
            'abs_pp' => $abs, 'rel' => $rel, 'c1_win' => $win,
            'immediate_goodput' => $i['goodput_ops_s'], 'hybrid_goodput' => $h['goodput_ops_s'],
            'immediate_p95_ms' => $i['lat_all_us']['p95'] / 1000, 'hybrid_p95_ms' => $h['lat_all_us']['p95'] / 1000,
            'immediate_first' => $i['order_in_round'] === 1,
        ];
    }
    $paired = array_intersect(array_keys($imm), array_keys($hyb));
    $imm = array_intersect_key($imm, array_flip($paired));
    $hyb = array_intersect_key($hyb, array_flip($paired));
    $pi = pool($imm);
    $ph = pool($hyb);
    $nr = count($roundRows);
    $need = (int) ceil(C1_ROUND_FRACTION * $nr);

    $absPooled = ($pi['exhausted_rate'] - $ph['exhausted_rate']) * 100;
    $relPooled = $pi['exhausted_rate'] > 0 ? ($pi['exhausted_rate'] - $ph['exhausted_rate']) / $pi['exhausted_rate'] : 0.0;
    [$ciLo, $ciHi] = newcombe($ph['exhausted'], $ph['ops'], $pi['exhausted'], $pi['ops']);   // hybrid - immediate
    $c1Pooled = $relPooled >= C1_REL && $absPooled >= C1_ABS_PP;
    $c1Rounds = $wins >= $need;
    $c1CiExcludesZero = $ciHi < 0;

    $tpRatio = $pi['goodput_ops_s'] > 0 ? $ph['goodput_ops_s'] / $pi['goodput_ops_s'] : null;
    $completedRatio = $pi['completed_ops_s'] > 0 ? $ph['completed_ops_s'] / $pi['completed_ops_s'] : null;
    // Throughput is goodput (successful ops per active second). Completed ops/s counts a fast failure as work done,
    // so it is reported (informational) but is not a criterion; see METHODOLOGY.md section 6.
    $c2 = $tpRatio !== null && $tpRatio >= 1 - C2_MAX_THROUGHPUT_DROP;

    $p95i = $pi['lat_all_us']['p95'];
    $p95h = $ph['lat_all_us']['p95'];
    $allowance = max(C3_REL * $p95i, C3_ABS_US);
    $c3 = $p95h <= $p95i + $allowance;

    $c4 = $pi['integrity_pass_all_runs'] && $ph['integrity_pass_all_runs'];

    $sufficient = $nr >= MIN_ROUNDS && min(array_column($imm, 'ops')) >= MIN_OPS_PER_ROUND && $pi['exhausted'] >= MIN_IMMEDIATE_EXHAUSTED_POOLED;

    return [
        'rounds' => $nr, 'rounds_needed_for_c1' => $need, 'c1_round_wins' => $wins,
        'sufficient_sample' => $sufficient,
        'immediate' => $pi, 'hybrid' => $ph, 'per_round' => $roundRows,
        'c1_exhausted' => [
            'pooled_abs_pp' => $absPooled, 'pooled_rel' => $relPooled, 'pooled_pass' => $c1Pooled, 'rounds_pass' => $c1Rounds,
            'diff_hybrid_minus_immediate_ci95_pp' => [$ciLo * 100, $ciHi * 100], 'ci_excludes_zero' => $c1CiExcludesZero,
            'pass' => $c1Pooled && $c1Rounds && $c1CiExcludesZero,
        ],
        'c2_throughput' => ['goodput_ratio_hybrid_over_immediate' => $tpRatio, 'completed_ratio_hybrid_over_immediate' => $completedRatio, 'pass' => $c2],
        'c3_p95' => ['immediate_us' => $p95i, 'hybrid_us' => $p95h, 'delta_us' => $p95h - $p95i, 'allowance_us' => $allowance, 'pass' => $c3],
        'c4_guarantees' => ['pass' => $c4],
    ];
}

/** Applies the pre-registered decision rule to one comparison. */
function decide(array $c): array
{
    if (!$c['sufficient_sample']) {
        return ['verdict' => 'INCONCLUSIVE', 'reason' => 'sample insufficient (rounds, ops per round, or Immediate exhausted operations below the pre-registered minimum)'];
    }
    if (!$c['c4_guarantees']['pass']) {
        return ['verdict' => 'INCONCLUSIVE', 'reason' => 'a transactional guarantee or integrity check failed in at least one run; no policy can be recommended until it is understood'];
    }
    $c1 = $c['c1_exhausted'];
    if ($c1['pass'] && $c['c2_throughput']['pass'] && $c['c3_p95']['pass']) {
        return ['verdict' => 'HYBRID', 'reason' => 'all pre-registered criteria C1-C4 pass'];
    }
    $c1Mixed = $c1['pooled_pass'] && !$c1['rounds_pass'] && $c['c1_round_wins'] >= (int) floor(0.5 * $c['rounds']);
    if ($c1Mixed) {
        return ['verdict' => 'INCONCLUSIVE', 'reason' => 'pooled exhaustion improvement passes but is not consistent across rounds'];
    }
    $failed = [];
    foreach (['c1_exhausted' => 'C1 exhaustion', 'c2_throughput' => 'C2 throughput', 'c3_p95' => 'C3 P95'] as $k => $label) {
        if (!$c[$k]['pass']) {
            $failed[] = $label;
        }
    }

    return ['verdict' => 'IMMEDIATE', 'reason' => 'Hybrid fails ' . implode(', ', $failed) . ' on an adequate sample'];
}

function fmt(?float $v, int $d = 2): string
{
    return $v === null ? 'n/a' : number_format($v, $d, '.', '');
}

/**
 * Host-condition evidence: load.json is never trusted on its own. It must describe exactly the declared runs, and its
 * per-run sample counts / noisy classification and noisy_runs list must equal what is re-computed here from the raw
 * host-load.log and the run summaries. New evidence additionally needs the process-tree attribution (host-quiet v2).
 *
 * @param list<string> $expectedRuns run names without extension
 * @return list<string> problems
 */
function validateLoadEvidence(string $dir, array $expectedRuns, bool $allowLegacy): array
{
    $problems = [];
    if (!is_file($dir . '/load.json')) {
        return $allowLegacy ? [] : ['load.json missing: host conditions during the runs are not recorded (run load-report.php, or pass --allow-legacy=1 for legacy evidence)'];
    }
    $recorded = json_decode((string) file_get_contents($dir . '/load.json'), true);
    if (!is_array($recorded) || !isset($recorded['runs'], $recorded['noisy_runs']) || !is_array($recorded['runs']) || !is_array($recorded['noisy_runs'])) {
        return ['load.json is malformed (needs "runs" and "noisy_runs")'];
    }
    if (!is_file($dir . '/host-load.log')) {
        return ['host-load.log missing: load.json cannot be verified'];
    }
    $computed = computeLoadReport($dir);
    foreach (array_diff($expectedRuns, array_keys($recorded['runs'])) as $m) {
        $problems[] = "load.json has no record for declared run $m";
    }
    foreach (array_diff(array_keys($recorded['runs']), $expectedRuns) as $u) {
        $problems[] = "load.json has a record for an undeclared run $u";
    }
    foreach ($expectedRuns as $name) {
        $r = $recorded['runs'][$name] ?? null;
        $c = $computed['runs'][$name] ?? null;
        if ($r === null || $c === null) {
            continue;
        }
        if (!is_int($r['samples'] ?? null) || !is_bool($r['noisy'] ?? null)) {
            $problems[] = "load.json record for $name is malformed (samples/noisy)";
            continue;
        }
        if ($r['samples'] !== $c['samples'] || $r['noisy'] !== $c['noisy'] || ($r['invalid_samples'] ?? 0) !== $c['invalid_samples']) {
            $problems[] = "load.json record for $name (samples={$r['samples']}, noisy=" . json_encode($r['noisy']) . ") does not match the host-load.log recomputation (samples={$c['samples']}, noisy=" . json_encode($c['noisy']) . ')';
        }
        if ($c['invalid_samples'] > 0 && !$allowLegacy) {
            $problems[] = "host sensor failed during run $name ({$c['invalid_samples']} INVALID sample(s)): host conditions unknown";
        }
        if ($c['samples'] === 0 && !$allowLegacy) {
            $problems[] = "no host sample overlaps run $name";
        }
    }
    $listed = $recorded['noisy_runs'];
    sort($listed);
    $real = $computed['noisy_runs'];
    sort($real);
    if ($listed !== $real) {
        $problems[] = 'load.json noisy_runs does not equal the recomputed set';
    }
    if ($computed['attribution'] !== 'process-tree-v2' && !$allowLegacy) {
        $problems[] = 'host-load.log uses the superseded attribution "' . $computed['attribution'] . '" (not process-tree v2); pass --allow-legacy=1 to analyse it explicitly as legacy host evidence';
    }

    return $problems;
}

/**
 * Fail-closed input validation. Returns a list of problems (empty = the evidence set is complete, matched and consistent).
 *
 * @return array{problems:list<string>,files:array<string,string>,legacy:bool}
 */
function validateEvidence(string $dir, bool $allowLegacy): array
{
    $problems = [];
    $manifestFile = $dir . '/manifest.json';
    if (!is_file($manifestFile)) {
        return ['problems' => ['manifest.json missing: cannot establish which runs must exist'], 'files' => [], 'legacy' => false];
    }
    $manifest = json_decode((string) file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
    $expected = [];
    foreach ($manifest['cells'] as $tag => $p) {
        foreach ($p['levels'] as $level) {
            for ($round = 1; $round <= $p['rounds']; ++$round) {
                foreach ($p['policies'] as $policy) {
                    $expected[sprintf('%s_c%02d_r%02d_%s.jsonl.gz', $tag, $level, $round, $policy)] = [$tag, $level, $round, $policy, $p];
                }
            }
        }
        $hasBoth = in_array('immediate', $p['policies'], true) && in_array('hybrid', $p['policies'], true);
        if (!$hasBoth && count($p['policies']) !== count(array_unique($p['policies']))) {
            $problems[] = "tag $tag: duplicate policy in manifest";
        }
    }
    $present = array_map('basename', glob($dir . '/runs/*') ?: []);
    foreach (array_diff(array_keys($expected), $present) as $missing) {
        $problems[] = "missing run file: $missing";
    }
    foreach (array_diff($present, array_keys($expected)) as $extra) {
        $problems[] = "unexpected run file not declared in manifest: $extra";
    }
    $hashes = [];
    $versions = [];
    $legacy = false;
    $seen = [];
    foreach ($expected as $name => [$tag, $level, $round, $policy, $p]) {
        $file = $dir . '/runs/' . $name;
        if (!is_file($file)) {
            continue;
        }
        try {
            $run = readRun($file);
        } catch (\Throwable $e) {
            $problems[] = "$name: unreadable (" . $e->getMessage() . ')';
            continue;
        }
        $s = $run['summary'];
        if ($s === null) {
            $problems[] = "$name: no summary line";
            continue;
        }
        $meta = ['tag' => $tag, 'connections' => $level, 'round' => $round, 'policy' => $policy, 'ops' => $p['ops'], 'placement' => $p['placement'], 'mode' => $p['mode'], 'verify_server_state' => $p['verify']];
        foreach ($meta as $k => $v) {
            if (($s[$k] ?? null) !== $v) {
                $problems[] = "$name: summary $k=" . json_encode($s[$k] ?? null) . ' does not match manifest/file name (' . json_encode($v) . ')';
            }
        }
        $key = $tag . '|' . $level . '|' . $round . '|' . $policy;
        if (isset($seen[$key])) {
            $problems[] = "$name: duplicate run for $key";
        }
        $seen[$key] = true;
        $ops = $run['ops'];
        if (count($ops) !== $p['ops'] || $s['ops'] !== $p['ops']) {
            $problems[] = "$name: " . count($ops) . ' raw operations, summary says ' . $s['ops'] . ', manifest says ' . $p['ops'];
        }
        $ids = array_column($ops, 'op');
        if (count(array_unique($ids)) !== count($ids) || count(array_unique(array_column($ops, 'product'))) !== count($ops)) {
            $problems[] = "$name: duplicate operation or product ids";
        }
        $byOutcome = array_count_values(array_column($ops, 'outcome'));
        foreach (['ok' => 'ok', 'exhausted' => 'exhausted', 'error' => 'errors', 'invalid' => 'invalid'] as $outcome => $field) {
            if (($byOutcome[$outcome] ?? 0) !== ($s[$field] ?? 0)) {
                $problems[] = "$name: raw outcome '$outcome' count " . ($byOutcome[$outcome] ?? 0) . " != summary $field " . ($s[$field] ?? 0);
            }
        }
        $rawDeadlocks = 0;
        foreach ($ops as $op) {
            foreach ($op['attempts'] as $at) {
                $rawDeadlocks += $at[2] === 1213 ? 1 : 0;
                if (!array_key_exists(4, $at)) {
                    $legacy = true;
                }
            }
        }
        if ($rawDeadlocks !== $s['client_deadlocks']) {
            $problems[] = "$name: raw 1213 attempts $rawDeadlocks != summary client_deadlocks " . $s['client_deadlocks'];
        }
        $hashes[$s['harness_sha256'] ?? 'absent'] = true;
        $versions[$s['mysql_version'] ?? 'absent'] = true;
    }
    if (count($hashes) > 1) {
        $problems[] = 'runs were produced by different harness versions: ' . implode(', ', array_map(static fn($h) => substr((string) $h, 0, 12), array_keys($hashes)));
    }
    if (count($versions) > 1) {
        $problems[] = 'runs were produced on different MySQL versions: ' . implode(', ', array_keys($versions));
    }
    array_push($problems, ...validateLoadEvidence($dir, array_map(static fn(string $n): string => basename($n, '.jsonl.gz'), array_keys($expected)), $allowLegacy));
    if (isset($hashes['absent']) && !$allowLegacy) {
        $problems[] = 'runs carry no harness hash (legacy run files); pass --allow-legacy=1 to analyse them explicitly as legacy evidence';
    }
    if ($legacy && !$allowLegacy) {
        $problems[] = 'attempt records carry no SQLSTATE (legacy run files); pass --allow-legacy=1 to analyse them explicitly as legacy evidence';
    }

    return ['problems' => $problems, 'files' => array_keys($expected), 'legacy' => $legacy || isset($hashes['absent'])];
}

function main(array $argv): void
{
    $a = args($argv);
    $dir = rtrim($a['dir'] ?? throw new InvalidArgumentException('--dir required'), '/');
    $validation = validateEvidence($dir, ($a['allow-legacy'] ?? '0') === '1');
    if ($validation['problems'] !== []) {
        fwrite(STDERR, "EVIDENCE VALIDATION FAILED — no analysis written:\n - " . implode("\n - ", $validation['problems']) . "\n");
        exit(3);
    }
    $noisyRuns = is_file($dir . '/load.json') ? array_flip(computeLoadReport($dir)['noisy_runs']) : null;
    $cells = [];   // tag => level => policy => round => metrics
    foreach (glob($dir . '/runs/*.jsonl.gz') ?: [] as $file) {
        $run = readRun($file);
        $m = runMetrics($run);
        $s = $run['summary'];
        $cells[$s['tag']][$s['connections']][$s['policy']][$s['round']] = $m;
    }
    ksort($cells);
    $out = ['dir' => $dir, 'generated_at' => date('c'), 'evidence_validated' => true, 'legacy_run_files' => $validation['legacy'], 'run_files' => count($validation['files']), 'constants_note' => 'see METHODOLOGY.md section 6', 'constants' => [
        'MIN_ROUNDS' => MIN_ROUNDS, 'MIN_OPS_PER_ROUND' => MIN_OPS_PER_ROUND, 'MIN_IMMEDIATE_EXHAUSTED_POOLED' => MIN_IMMEDIATE_EXHAUSTED_POOLED,
        'C1_REL' => C1_REL, 'C1_ABS_PP' => C1_ABS_PP, 'C1_ROUND_FRACTION' => C1_ROUND_FRACTION, 'C2_MAX_THROUGHPUT_DROP' => C2_MAX_THROUGHPUT_DROP,
        'C3_REL' => C3_REL, 'C3_ABS_US' => C3_ABS_US,
    ], 'cells' => []];
    $md = ["# Analysis output — `" . basename($dir) . "`\n", 'Evidence validation: ' . count($validation['files']) . ' declared run files present, matched and internally consistent' . ($validation['legacy'] ? ' (**legacy run files, analysed with --allow-legacy=1: attempt records carry no SQLSTATE**)' : '') . '.', '', 'Generated by `harness/analyze.php`; all numbers derive from `runs/*.jsonl.gz`. Latencies in ms, throughput in successful ops per active second.', ''];

    foreach ($cells as $tag => $levels) {
        ksort($levels);
        foreach ($levels as $level => $policies) {
            foreach ($policies as &$rounds) {
                ksort($rounds);
            }
            unset($rounds);
            $key = $tag . '/c' . $level;
            $md[] = "## `$tag` — $level connections";
            if (isset($policies['immediate'], $policies['hybrid'])) {
                $c = compare($policies['immediate'], $policies['hybrid']);
                $d = decide($c);
                $noisyInCell = 0;
                foreach (['immediate', 'hybrid'] as $pol) {
                    foreach (array_keys($policies[$pol]) as $rd) {
                        $noisyInCell += isset($noisyRuns[sprintf('%s_c%02d_r%02d_%s', $tag, $level, $rd, $pol)]) ? 1 : 0;
                    }
                }
                if ($noisyRuns !== null && $noisyInCell > 0 && in_array($d['verdict'], ['HYBRID', 'IMMEDIATE'], true)) {
                    $d = ['verdict' => 'INCONCLUSIVE', 'reason' => $noisyInCell . ' run(s) of this cell overlapped foreign host load (load.json); latency/throughput acceptance is EVIDENCE REQUIRED (rule outcome would have been ' . $d['verdict'] . ')'];
                }
                $c['host_noisy_runs'] = $noisyInCell;
                $out['cells'][$key] = ['comparison' => $c, 'decision' => $d];
                $pi = $c['immediate'];
                $ph = $c['hybrid'];
                $md[] = '';
                $md[] = '| metric (pooled over ' . $c['rounds'] . ' rounds) | Immediate | Hybrid |';
                $md[] = '|---|---:|---:|';
                $rows = [
                    ['operations', $pi['ops'], $ph['ops']],
                    ['succeeded', $pi['ok'], $ph['ok']],
                    ['exhausted (4 attempts all 1213)', $pi['exhausted'], $ph['exhausted']],
                    ['exhausted rate %', fmt($pi['exhausted_rate'] * 100), fmt($ph['exhausted_rate'] * 100)],
                    ['exhausted rate 95% Wilson %', fmt($pi['exhausted_wilson95'][0] * 100) . '–' . fmt($pi['exhausted_wilson95'][1] * 100), fmt($ph['exhausted_wilson95'][0] * 100) . '–' . fmt($ph['exhausted_wilson95'][1] * 100)],
                    ['original deadlocks (attempt 1)', $pi['deadlocks_original'], $ph['deadlocks_original']],
                    ['repeated deadlocks (attempt ≥ 2)', $pi['deadlocks_repeated'], $ph['deadlocks_repeated']],
                    ['attempts per op', fmt($pi['attempts_per_op'], 3), fmt($ph['attempts_per_op'], 3)],
                    ['goodput ops/s', fmt($pi['goodput_ops_s'], 1), fmt($ph['goodput_ops_s'], 1)],
                    ['completed ops/s', fmt($pi['completed_ops_s'], 1), fmt($ph['completed_ops_s'], 1)],
                    ['latency P50 ms (all ops)', fmt($pi['lat_all_us']['p50'] / 1000), fmt($ph['lat_all_us']['p50'] / 1000)],
                    ['latency P95 ms (all ops)', fmt($pi['lat_all_us']['p95'] / 1000), fmt($ph['lat_all_us']['p95'] / 1000)],
                    ['latency P99 ms (all ops)', fmt($pi['lat_all_us']['p99'] / 1000), fmt($ph['lat_all_us']['p99'] / 1000)],
                    ['latency P95 ms (succeeded only)', fmt($pi['lat_ok_us']['p95'] / 1000), fmt($ph['lat_ok_us']['p95'] / 1000)],
                    ['total jitter sleep ms', fmt($pi['sleep_total_ms'], 0), fmt($ph['sleep_total_ms'], 0)],
                    ['succeeded by attempts used', json_encode($pi['ok_by_attempts']), json_encode($ph['ok_by_attempts'])],
                    ['integrity / guarantees (all runs)', $pi['integrity_pass_all_runs'] ? 'pass' : 'FAIL', $ph['integrity_pass_all_runs'] ? 'pass' : 'FAIL'],
                ];
                foreach ($rows as $r) {
                    $md[] = '| ' . implode(' | ', $r) . ' |';
                }
                $c1 = $c['c1_exhausted'];
                $md[] = '';
                $md[] = sprintf(
                    '**Criteria** — C1 exhaustion: pooled Δ=%s pp (%s%% relative), 95%% CI of (Hybrid−Immediate) = [%s, %s] pp, rounds passing %d/%d (need %d) → **%s**; C2 goodput ratio %s (completed-ops ratio %s, informational) → **%s**; C3 P95 Δ=%s ms (allowance %s ms) → **%s**; C4 guarantees → **%s**; sufficient sample: %s.',
                    fmt($c1['pooled_abs_pp']), fmt($c1['pooled_rel'] * 100, 1), fmt($c1['diff_hybrid_minus_immediate_ci95_pp'][0]), fmt($c1['diff_hybrid_minus_immediate_ci95_pp'][1]),
                    $c['c1_round_wins'], $c['rounds'], $c['rounds_needed_for_c1'], $c1['pass'] ? 'pass' : 'fail',
                    fmt($c['c2_throughput']['goodput_ratio_hybrid_over_immediate'], 3), fmt($c['c2_throughput']['completed_ratio_hybrid_over_immediate'], 3), $c['c2_throughput']['pass'] ? 'pass' : 'fail',
                    fmt($c['c3_p95']['delta_us'] / 1000), fmt($c['c3_p95']['allowance_us'] / 1000), $c['c3_p95']['pass'] ? 'pass' : 'fail',
                    $c['c4_guarantees']['pass'] ? 'pass' : 'fail', $c['sufficient_sample'] ? 'yes' : 'NO',
                );
                $md[] = '';
                $md[] = $noisyRuns === null ? 'Host conditions: not recorded (legacy evidence).' : 'Host conditions: ' . $noisyInCell . ' of ' . (2 * $c['rounds']) . ' runs flagged noisy (load.json).';
                $md[] = '';
                $md[] = '**Rule outcome for this cell:** `' . $d['verdict'] . '` — ' . $d['reason'] . '.';
                $md[] = '';
                $md[] = '| round | first | Imm exh % | Hyb exh % | Δpp | rel | C1 | Imm goodput | Hyb goodput | Imm P95 | Hyb P95 |';
                $md[] = '|---:|---|---:|---:|---:|---:|---|---:|---:|---:|---:|';
                foreach ($c['per_round'] as $r) {
                    $md[] = sprintf(
                        '| %d | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |',
                        $r['round'], $r['immediate_first'] ? 'I' : 'H', fmt($r['immediate_exhausted_pct']), fmt($r['hybrid_exhausted_pct']), fmt($r['abs_pp']),
                        fmt($r['rel'] * 100, 0) . '%', $r['c1_win'] ? 'win' : '—', fmt($r['immediate_goodput'], 1), fmt($r['hybrid_goodput'], 1),
                        fmt($r['immediate_p95_ms']), fmt($r['hybrid_p95_ms']),
                    );
                }
            } else {
                $md[] = '';
                $md[] = '| policy | rounds | ops | ok | exhausted % | original deadlock % of ops | attempts/op | integrity |';
                $md[] = '|---|---:|---:|---:|---:|---:|---:|---|';
                foreach ($policies as $policy => $rounds) {
                    $p = pool($rounds);
                    $md[] = sprintf('| %s | %d | %d | %d | %s | %s | %s | %s |', $policy, $p['rounds'], $p['ops'], $p['ok'], fmt($p['exhausted_rate'] * 100), fmt($p['original_deadlock_rate'] * 100), fmt($p['attempts_per_op'], 3), $p['integrity_pass_all_runs'] ? 'pass' : 'FAIL');
                    $out['cells'][$key]['policies'][$policy] = $p;
                }
            }
            $md[] = '';
            foreach ($policies as $policy => $rounds) {
                foreach ($rounds as $r) {
                    if (!$r['integrity_pass']) {
                        $md[] = "- ⚠ integrity/guarantee check FAILED: `$tag` c=$level $policy round {$r['round']}";
                    }
                }
            }
        }
    }
    foreach ($out['cells'] as &$cell) {
        foreach (['immediate', 'hybrid'] as $p) {
            unset($cell['comparison'][$p]['_lat_all']);
        }
    }
    unset($cell);
    file_put_contents($dir . '/analysis.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($dir . '/ANALYSIS.md', implode("\n", $md) . "\n");
    echo implode("\n", $md), "\n";
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    main($argv);
}
