<?php

/**
 * Issue #32 study harness: self-tests of the harness itself (negative tests).
 *
 *   php selftest.php                 deterministic decision-branch tests (no database)
 *   php selftest.php --with-db=1     + tests that need the study MySQL (reuse guard, observer sensitivity)
 *
 * The retry-branch tests use fakes ONLY to exercise the classification/ownership decisions of executeWithPolicy();
 * the deadlock evidence itself comes from the real-MySQL runs.
 */

declare(strict_types=1);

namespace Maatify\Persistence\Study;

require __DIR__ . '/lib.php';
require_once __DIR__ . '/analyze.php';

use PDOException;

$failures = 0;
$total = 0;
function t(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $total;
    ++$total;
    if (!$ok) {
        ++$failures;
    }
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
}

function pdoException(int $code, string $state): PDOException
{
    $e = new PDOException('fake');
    $e->errorInfo = [$state, $code, 'fake'];

    return $e;
}

/** @param list<\Throwable|null> $script one entry per attempt: null = success */
function drive(Policy $policy, array $script, bool $txOpenBefore = false, ?bool $txOpenAfterFailure = null, array $serverOpen = []): array
{
    $calls = 0;
    $sleeps = [];
    $inTx = false;
    $result = executeWithPolicy(
        $policy,
        static function (int $n) use (&$calls, $script, &$inTx, $txOpenAfterFailure): void {
            ++$calls;
            array_key_exists($n - 1, $script) || throw new \LogicException('script exhausted');
            $step = $script[$n - 1];
            if ($step !== null) {
                $inTx = $txOpenAfterFailure ?? false;
                throw $step;
            }
        },
        static function () use (&$inTx, $txOpenBefore, &$calls): bool {
            return $inTx || ($txOpenBefore && $calls === 0);
        },
        $serverOpen === [] ? null : static fn(): array => $serverOpen,
        static function (int $us) use (&$sleeps): void {
            $sleeps[] = $us;
        },
        static fn(): int => hrtime(true),
        hrtime(true),
    );

    return $result + ['calls' => $calls, 'sleeps' => $sleeps];
}

$deadlock = static fn(): PDOException => pdoException(1213, '40001');

// ---- classifier / retry decisions ----
$r = drive(Policy::fromName('immediate', 1), [$deadlock(), $deadlock(), null]);
t('1213+40001 is retried until success', $r['outcome'] === 'ok' && $r['calls'] === 3);

$r = drive(Policy::fromName('immediate', 1), [pdoException(1213, 'HY000'), null]);
t('1213 with SQLSTATE HY000 is NOT retried', $r['outcome'] === 'error' && $r['calls'] === 1 && $r['attempts'][0][2] === 1213 && $r['attempts'][0][4] === 'HY000');

$r = drive(Policy::fromName('immediate', 1), [pdoException(1205, 'HY000'), null]);
t('1205 lock wait timeout is NOT retried', $r['outcome'] === 'error' && $r['calls'] === 1);

$r = drive(Policy::fromName('immediate', 1), [pdoException(1062, '23000'), null]);
t('1062 duplicate key is NOT retried', $r['outcome'] === 'error' && $r['calls'] === 1);

$r = drive(Policy::fromName('immediate', 1), [new \RuntimeException('boom'), null]);
t('non-PDO Throwable is NOT retried', $r['outcome'] === 'error' && $r['calls'] === 1 && $r['attempts'][0][2] === -1 && str_contains((string) $r['other_error'], 'RuntimeException'));

$r = drive(Policy::fromName('immediate', 1), [$deadlock(), null], false, true);
t('transaction still active after a deadlock => invalid, no retry', $r['outcome'] === 'invalid' && $r['calls'] === 1 && $r['flags'] !== []);

$r = drive(Policy::fromName('immediate', 1), [null], true);
t('transaction active before the first attempt => invalid, runner never called', $r['outcome'] === 'invalid' && $r['calls'] === 0 && $r['attempts'] === []);

$r = drive(Policy::fromName('immediate', 1), [$deadlock(), null], false, null, [['STATE' => 'ACTIVE']]);
t('server reports an ACTIVE transaction before a retry => invalid, no retry', $r['outcome'] === 'invalid' && $r['calls'] === 1);

$r = drive(Policy::fromName('immediate', 1), array_fill(0, 4, $deadlock()));
t('Immediate exhausts after exactly 4 attempts, no sleeps', $r['outcome'] === 'exhausted' && $r['calls'] === 4 && $r['sleeps'] === []);

$bad = 0;
for ($seed = 1; $seed <= 200; ++$seed) {
    $r = drive(Policy::fromName('hybrid', $seed), array_fill(0, 4, $deadlock()));
    $s = array_map(static fn(array $a): int => $a[3], $r['attempts']);
    if ($r['outcome'] !== 'exhausted' || $r['calls'] !== 4 || $s[0] !== 0 || $s[1] < 0 || $s[1] > 10_000 || $s[2] < 0 || $s[2] > 20_000 || $s[3] !== 0) {
        ++$bad;
    }
}
t('Hybrid: 4 attempts, retry1 immediate, retry2 in [0,10ms], retry3 in [0,20ms], none after exhaustion (200 seeds)', $bad === 0);

$r = drive(Policy::fromName('none', 1), [$deadlock(), null]);
t('policy none: exactly one attempt', $r['outcome'] === 'exhausted' && $r['calls'] === 1);

// ---- analyzer: fail-closed evidence validation ----
function writeRunFile(string $dir, string $tag, int $level, int $round, string $policy, array $over = [], int $ops = 4): void
{
    $lines = [];
    $attempts = $policy === 'hybrid' ? 2 : 1;
    for ($i = 1; $i <= $ops; ++$i) {
        $at = [];
        $dl = 0;
        for ($k = 1; $k < $attempts; ++$k) {
            $at[] = [0, 10, 1213, 0, '40001'];
            ++$dl;
        }
        $at[] = [0, 10, 0, 0, ''];
        $lines[] = ['type' => 'op', 'op' => $i, 'product' => $i, 'outcome' => 'ok', 'attempts' => $at, 'callback_runs' => count($at), 'latency_us' => 20, 'flags' => [], 'other_error' => null];
    }
    $deadlocks = ($attempts - 1) * $ops;
    $summary = ['type' => 'summary', 'tag' => $tag, 'policy' => $policy, 'connections' => $level, 'ops' => $ops, 'round' => $round, 'order_in_round' => 1, 'placement' => 'sequential', 'mode' => 'wave', 'verify_server_state' => false,
        'started_at' => gmdate('c', $over['end'] ?? 1_800_000_000), 'active_us' => 1000, 'wall_us' => 2000, 'wave_active_us' => [], 'ok' => $ops, 'exhausted' => 0, 'errors' => 0, 'invalid' => 0, 'harness_sha256' => 'h1', 'mysql_version' => '8.0.46',
        'client_deadlocks' => $deadlocks, 'server_deadlocks_delta' => $deadlocks,
        'integrity' => ['contents_rows' => $ops, 'ok_ops' => $ops, 'rows_match_ok_ops' => true, 'duplicate_product_rows' => 0, 'name_mismatch_rows' => 0, 'callback_runs_ne_attempts' => 0, 'server_open_transactions_after_run' => 0, 'flags' => [], 'other_errors' => [], 'all_isolation_repeatable_read' => true]];
    $summary = array_replace($summary, $over['summary'] ?? []);
    if (isset($over['ops'])) {
        $lines = $over['ops']($lines);
    }
    $gz = gzopen(sprintf('%s/runs/%s_c%02d_r%02d_%s.jsonl.gz', $dir, $tag, $level, $round, $policy), 'wb');
    foreach ($lines as $l) {
        gzwrite($gz, json_encode($l) . "\n");
    }
    gzwrite($gz, json_encode($summary) . "\n");
    gzclose($gz);
}

const HOST_BASE = 1_800_000_000;

/** Synthetic host-load.log: one sample per second; $heavy = list of [fromOffset, toOffset] with 600 % foreign CPU. */
function writeHostLog(string $dir, int $seconds, array $heavy = [], string $suffix = ' v=2', string $dsl = 'foreign'): void
{
    $lines = [];
    for ($t = 0; $t <= $seconds; ++$t) {
        $f = 50;
        foreach ($heavy as [$a, $b]) {
            if ($t >= $a && $t <= $b) {
                $f = 600;
            }
        }
        $lines[] = gmdate('Y-m-d\TH:i:s\Z', HOST_BASE + $t) . " foreign=$f load1=3.0" . $suffix;
    }
    file_put_contents($dir . '/host-load.log', implode("\n", $lines) . "\n");
}

function writeLoadJson(string $dir): void
{
    file_put_contents($dir . '/load.json', json_encode(computeLoadReport($dir), JSON_PRETTY_PRINT));
}

function makeSet(array $overrides = [], int $rounds = 2, array $heavy = [], string $logSuffix = ' v=2'): string
{
    $dir = sys_get_temp_dir() . '/i32-selftest-' . bin2hex(random_bytes(4));
    mkdir($dir . '/runs', 0777, true);
    file_put_contents($dir . '/manifest.json', json_encode(['cells' => ['t' => ['levels' => [2], 'rounds' => $rounds, 'ops' => 4, 'placement' => 'sequential', 'mode' => 'wave', 'policies' => ['immediate', 'hybrid'], 'verify' => false]]]));
    $i = 0;
    for ($round = 1; $round <= $rounds; ++$round) {
        foreach (['immediate', 'hybrid'] as $policy) {
            ++$i;
            writeRunFile($dir, 't', 2, $round, $policy, ['end' => HOST_BASE + $i * 20] + ($overrides[$round . $policy] ?? []));
        }
    }
    writeHostLog($dir, ($i + 1) * 20, $heavy, $logSuffix);
    writeLoadJson($dir);

    return $dir;
}

$has = static fn(array $v, string $needle): bool => (bool) array_filter($v['problems'], static fn(string $p): bool => str_contains($p, $needle));

t('analyzer: a complete, matched, consistent set validates', validateEvidence(makeSet(), false)['problems'] === []);

$dir = makeSet();
unlink($dir . '/runs/t_c02_r02_hybrid.jsonl.gz');
t('analyzer: missing Hybrid file for a round is rejected', $has(validateEvidence($dir, false), 'missing run file: t_c02_r02_hybrid'));

$dir = makeSet();
copy($dir . '/runs/t_c02_r01_hybrid.jsonl.gz', $dir . '/runs/t_c04_r01_hybrid.jsonl.gz');
t('analyzer: undeclared extra / stale run file is rejected', $has(validateEvidence($dir, false), 'unexpected run file'));

$dir = makeSet(['1hybrid' => ['summary' => ['ops' => 5]]]);
t('analyzer: summary op count != raw / manifest is rejected', $has(validateEvidence($dir, false), 'raw operations'));

$dir = makeSet(['2immediate' => ['summary' => ['ok' => 3, 'exhausted' => 1]]]);
t('analyzer: summary outcome counts != raw outcomes are rejected', $has(validateEvidence($dir, false), "raw outcome 'ok'"));

$dir = makeSet(['1immediate' => ['ops' => static function (array $lines): array {
    $lines[1]['op'] = $lines[0]['op'];

    return $lines;
}]]);
t('analyzer: duplicate operation ids are rejected', $has(validateEvidence($dir, false), 'duplicate operation or product ids'));

$dir = makeSet(['2hybrid' => ['summary' => ['round' => 1]]]);
t('analyzer: file name / summary metadata mismatch (stale or duplicated run) is rejected', $has(validateEvidence($dir, false), 'summary round'));

$dir = makeSet(['2hybrid' => ['summary' => ['client_deadlocks' => 99]]]);
t('analyzer: client deadlock count != raw 1213 attempts is rejected', $has(validateEvidence($dir, false), 'client_deadlocks'));

$dir = makeSet(['2hybrid' => ['summary' => ['harness_sha256' => 'h2']]]);
t('analyzer: runs from different harness versions are rejected', $has(validateEvidence($dir, false), 'different harness versions'));

$dir = makeSet();
unlink($dir . '/load.json');
t('analyzer: missing load.json (host conditions not recorded) is rejected unless --allow-legacy=1', $has(validateEvidence($dir, false), 'load.json missing') && !$has(validateEvidence($dir, true), 'load.json missing'));

// ---- host-condition evidence (F41-07): load.json is verified against the raw log, never trusted alone ----
$dir = makeSet();
file_put_contents($dir . '/load.json', json_encode(['noisy_runs' => []]));
t('host evidence: a bare {"noisy_runs":[]} (no per-run records) is rejected as malformed', $has(validateEvidence($dir, false), 'malformed'));

$dir = makeSet();
$j = json_decode((string) file_get_contents($dir . '/load.json'), true);
unset($j['runs']['t_c02_r01_immediate']);
file_put_contents($dir . '/load.json', json_encode($j));
t('host evidence: a declared run without a load.json record is rejected', $has(validateEvidence($dir, false), 'no record for declared run t_c02_r01_immediate'));

$dir = makeSet();
$j = json_decode((string) file_get_contents($dir . '/load.json'), true);
$j['runs']['t_c09_r09_hybrid'] = $j['runs']['t_c02_r01_hybrid'];
file_put_contents($dir . '/load.json', json_encode($j));
t('host evidence: a record for an undeclared run is rejected', $has(validateEvidence($dir, false), 'undeclared run t_c09_r09_hybrid'));

$dir = makeSet([], 2, [[15, 45]]);   // heavy foreign CPU while runs 1-2 execute
$j = json_decode((string) file_get_contents($dir . '/load.json'), true);
$noisy = $j['noisy_runs'];
t('host evidence: heavy foreign CPU overlapping a run is classified noisy by the recomputation', $noisy !== [] && validateEvidence($dir, false)['problems'] === []);
foreach ($j['runs'] as $name => &$rec) {
    $rec['noisy'] = false;
}
unset($rec);
$j['noisy_runs'] = [];
file_put_contents($dir . '/load.json', json_encode($j));
t('host evidence: load.json that hides noisy runs (all noisy=false) is rejected', $has(validateEvidence($dir, false), 'does not match the host-load.log recomputation'));

$dir = makeSet();
$j = json_decode((string) file_get_contents($dir . '/load.json'), true);
$j['runs']['t_c02_r01_hybrid']['samples'] = 99;
file_put_contents($dir . '/load.json', json_encode($j));
t('host evidence: a wrong per-run sample count is rejected', $has(validateEvidence($dir, false), 'does not match the host-load.log recomputation'));

$dir = makeSet([], 2, [[15, 45]]);
$j = json_decode((string) file_get_contents($dir . '/load.json'), true);
$j['noisy_runs'] = [];
file_put_contents($dir . '/load.json', json_encode($j));
t('host evidence: a noisy_runs list that differs from the recomputed set is rejected', $has(validateEvidence($dir, false), 'noisy_runs does not equal'));

$dir = makeSet();
unlink($dir . '/host-load.log');
t('host evidence: load.json without the raw host-load.log is rejected', $has(validateEvidence($dir, false), 'host-load.log missing'));

$dir = makeSet();
file_put_contents($dir . '/host-load.log', "garbage\n");
$j = json_decode((string) file_get_contents($dir . '/load.json'), true);
t('host evidence: a log with no usable samples cannot validate (no sample overlaps the runs)', $has(validateEvidence($dir, false), 'no host sample overlaps run'));

$dir = makeSet([], 2, [], '');
t('host evidence: v1 (process-name) attribution is rejected unless --allow-legacy=1', $has(validateEvidence($dir, false), 'superseded attribution') && !$has(validateEvidence($dir, true), 'superseded attribution'));

$dir = makeSet();
unlink($dir . '/manifest.json');
t('analyzer: missing manifest is rejected', $has(validateEvidence($dir, false), 'manifest.json missing'));

$dir = makeSet(['1immediate' => ['ops' => static function (array $lines): array {
    foreach ($lines as &$l) {
        $l['attempts'] = array_map(static fn(array $a): array => array_slice($a, 0, 4), $l['attempts']);
    }

    return $lines;
}]]);
t('analyzer: legacy attempt records (no SQLSTATE) are rejected unless --allow-legacy=1', $has(validateEvidence($dir, false), 'no SQLSTATE') && !$has(validateEvidence($dir, true), 'no SQLSTATE'));

// ---- sensor failure paths (F41-10): a broken sensor must never read as 0 % / clean ----
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/i32-sensor-' . bin2hex(random_bytes(3));
mkdir($tmp);
$write = static function (string $name, string $body) use ($tmp): string {
    file_put_contents($tmp . '/' . $name, "#!/usr/bin/env bash\n" . $body . "\n");
    chmod($tmp . '/' . $name, 0755);

    return $tmp . '/' . $name;
};
$table = "Processes: 1 total\nPID    COMMAND          %CPU\n1 a 0.0\n2 b 0.0\n3 c 0.0\n4 d 0.0\n5 e 0.0\nPID    COMMAND          %CPU\n900 com.apple.Virtualiz 300.0\n901 unrelated 50.0\n902 x 0.0\n903 y 0.0\n904 z 0.0\n905 w 0.0\n";
$topOk = $write('top-ok', "cat <<'EOT'\n$table\nEOT");
$topFail = $write('top-fail', 'exit 1');
$topEmpty = $write('top-empty', 'exit 0');
$topGarbage = $write('top-garbage', "echo 'Processes: nothing useful'; echo 'PID COMMAND %CPU'");
$dockerFail = $write('docker-fail', 'exit 1');
$dockerEmpty = $write('docker-empty', 'exit 0');
$dockerNoStudy = $write('docker-nostudy', "echo 'other 10.00%'");
$dockerNonNumeric = $write('docker-nonnumeric', "echo 'study N/A'; echo 'other 5.00%'");
$dockerOk = $write('docker-ok', "echo 'study 80.00%'; echo 'other 5.00%'");
$hq = static function (string $top, string $docker) use ($root): array {
    $cmd = 'STUDY_ROOT_PID=' . getmypid() . ' STUDY_DOCKER_CONTAINER=study HOST_QUIET_TOP_CMD=' . escapeshellarg($top) . ' HOST_QUIET_DOCKER_CMD=' . escapeshellarg($docker) . ' ' . escapeshellarg($root . '/host-quiet.sh') . ' 2>/dev/null';
    exec($cmd, $out, $rc);

    return [trim(implode("\n", $out)), $rc];
};
foreach (['top exits non-zero' => $topFail, 'top prints nothing' => $topEmpty, 'top prints no usable second table' => $topGarbage] as $label => $top) {
    [$out, $rc] = $hq($top, $dockerOk);
    t("sensor: $label => INVALID, exit 1, never a number", $out === 'INVALID' && $rc === 1);
}
[$out, $rc] = $hq($topOk, $dockerOk);
t('sensor: valid top + valid docker stats => VM suppressed, foreign = 50 + other containers 5 (got ' . $out . ')', $rc === 0 && $out === '55');
foreach (['docker stats fails' => $dockerFail, 'docker stats prints nothing (exit 0)' => $dockerEmpty, 'docker stats lacks the study container' => $dockerNoStudy, 'docker stats value not numeric' => $dockerNonNumeric] as $label => $docker) {
    [$out, $rc] = $hq($topOk, $docker);
    t("sensor: $label => the Docker VM's 300 % is counted as foreign, not hidden (got $out, expected 350)", $rc === 0 && $out === '350');
}
$failing = $write('hq-fail', 'echo INVALID; exit 1');
$garbage = $write('hq-garbage', 'echo oops; exit 0');
foreach (['exits 1' => $failing, 'prints a non-number with exit 0' => $garbage] as $label => $bin) {
    $line = trim((string) shell_exec('HOST_QUIET_BIN=' . escapeshellarg($bin) . ' HOST_LOAD_ONCE=1 ' . escapeshellarg($root . '/host-load.sh') . ' 2>/dev/null'));
    t("sampler: a sensor that $label is logged as foreign=INVALID, never foreign=0", str_contains($line, 'foreign=INVALID') && !str_contains($line, 'foreign=0 '));
}
exec('HOST_QUIET_BIN=' . escapeshellarg($failing) . ' ' . escapeshellarg($root . '/host-gate.sh') . ' 8 200 3 2>&1', $o1, $rc1);
t('gate: a permanently failing sensor never passes (exit 3, invalid_samples reported)', $rc1 === 3 && str_contains(implode(' ', $o1), 'FAILED') && !str_contains(implode(' ', $o1), 'invalid_samples=0'));
$counter = $tmp . '/n';
file_put_contents($counter, '0');
$flaky = $write('hq-flaky', 'n=$(cat ' . escapeshellarg($counter) . '); n=$((n+1)); echo $n > ' . escapeshellarg($counter) . '; if [ $((n % 3)) -eq 0 ]; then echo INVALID; exit 1; fi; echo 10');
exec('HOST_QUIET_BIN=' . escapeshellarg($flaky) . ' ' . escapeshellarg($root . '/host-gate.sh') . ' 14 200 3 2>&1', $o2, $rc2);
t('gate: an INVALID sample resets the consecutive-quiet count (10,10,INVALID,… never reaches 3)', $rc2 === 3);
$steady = $write('hq-steady', 'echo 10');
exec('HOST_QUIET_BIN=' . escapeshellarg($steady) . ' ' . escapeshellarg($root . '/host-gate.sh') . ' 30 200 3 2>&1', $o3, $rc3);
t('gate: valid quiet samples pass (control)', $rc3 === 0 && str_contains(implode(' ', $o3), 'PASSED'));
$loud = $write('hq-loud', 'echo 900');
exec('HOST_QUIET_BIN=' . escapeshellarg($loud) . ' ' . escapeshellarg($root . '/host-gate.sh') . ' 8 200 3 2>&1', $o4, $rc4);
t('gate: valid but loud samples do not pass (control)', $rc4 === 3);

// host evidence containing a failed-sensor sample
$dir = makeSet();
$lines = file($dir . '/host-load.log', FILE_IGNORE_NEW_LINES);
$lines[40] = preg_replace('/foreign=\d+/', 'foreign=INVALID', $lines[40]);   // inside run 2's window
file_put_contents($dir . '/host-load.log', implode("\n", $lines) . "\n");
writeLoadJson($dir);
$v = validateEvidence($dir, false);
$j = json_decode((string) file_get_contents($dir . '/load.json'), true);
$flagged = array_filter($j['runs'], static fn(array $r): bool => $r['invalid_samples'] > 0);
t('host evidence: a run overlapping an INVALID sensor sample is classified noisy (unknown is never clean)', $flagged !== [] && count(array_filter($flagged, static fn(array $r): bool => !$r['noisy'])) === 0);
t('host evidence: new evidence with a failed sensor sample is rejected as unknown host conditions', $has($v, 'host sensor failed'));
foreach ($j['runs'] as &$rec) {
    $rec['noisy'] = false;
}
unset($rec);
$j['noisy_runs'] = [];
file_put_contents($dir . '/load.json', json_encode($j));
t('host evidence: load.json that calls a run clean despite an INVALID sample is rejected', $has(validateEvidence($dir, false), 'does not match the host-load.log recomputation'));
foreach (glob($tmp . '/*') ?: [] as $f) {
    unlink($f);
}
rmdir($tmp);

// ---- host-CPU attribution sensitivity (F41-08): needs real processes ----
if (in_array('--with-host=1', $argv, true)) {
    $hq = realpath(__DIR__ . '/../host-quiet.sh');
    $measure = static function (int $rootPid) use ($hq): float {
        $vals = [];
        for ($i = 0; $i < 3; ++$i) {
            $vals[] = (float) trim((string) shell_exec('STUDY_ROOT_PID=' . $rootPid . ' ' . escapeshellarg($hq) . ' 2>/dev/null'));
        }
        sort($vals);

        return $vals[1];
    };
    // One launcher L runs three CPU-busy processes whose names collide with the old name-based exclusion list
    // (worker.php, study.php, bash). The same busy set is measured twice back to back: once declaring L as the study
    // root (the busy processes are its descendants -> own -> excluded) and once declaring an UNRELATED root (they are
    // not its descendants -> foreign -> counted). Background noise on the host cancels out in the difference.
    $tmp = sys_get_temp_dir() . '/i32-hostq-' . bin2hex(random_bytes(3));
    mkdir($tmp);
    file_put_contents($tmp . '/worker.php', '<?php while (true) { }');
    file_put_contents($tmp . '/study.php', '<?php while (true) { }');
    $launcher = proc_open(['bash', '-c', escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp . '/worker.php') . ' & ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp . '/study.php') . ' & bash -c "while :; do :; done" & wait'], [], $p1);
    $launcherPid = (int) proc_get_status($launcher)['pid'];
    $idle = proc_open(['sleep', '300'], [], $p2);
    $idlePid = (int) proc_get_status($idle)['pid'];
    usleep(800_000);
    $ownSet = static fn(int $root): array => array_filter(explode(' ', trim((string) shell_exec('HOST_QUIET_PRINT_OWN=1 STUDY_ROOT_PID=' . $root . ' ' . escapeshellarg($hq) . ' 2>/dev/null'))));
    $busyPids = array_map('intval', array_filter(explode("\n", trim((string) shell_exec('pgrep -P ' . $launcherPid)))));
    $viewAsOwn = array_map('intval', $ownSet($launcherPid));
    $viewAsForeign = array_map('intval', $ownSet($idlePid));
    $asOwn = [];
    $asForeign = [];
    for ($i = 0; $i < 5; ++$i) {   // interleaved CPU measurements
        $asOwn[] = $measure($launcherPid);
        $asForeign[] = $measure($idlePid);
    }
    sort($asOwn);
    sort($asForeign);
    exec('pkill -P ' . $launcherPid . ' 2>/dev/null');
    proc_terminate($launcher);
    proc_close($launcher);
    proc_terminate($idle);
    proc_close($idle);
    array_map('unlink', glob($tmp . '/*.php') ?: []);
    rmdir($tmp);

    $diff = $asForeign[2] - $asOwn[2];
    t(sprintf('host attribution: the busy processes (%d of them, named worker.php/study.php/bash) are in the OWN set when descendants of the study root', count($busyPids)), count($busyPids) >= 3 && array_diff($busyPids, $viewAsOwn) === []);
    t('host attribution: the same processes are NOT in the own set when they are not descendants of the study root (name collisions do not make them own)', array_intersect($busyPids, $viewAsForeign) === []);
    t(sprintf('host attribution: measured foreign CPU reflects it (median of 5 interleaved pairs: %.0f%% as foreign vs %.0f%% as own, difference %.0f%%)', $asForeign[2], $asOwn[2], $diff), $diff >= 60);
}

// ---- tests that need the study MySQL ----
$withDb = in_array('--with-db=1', $argv, true);
if ($withDb) {
    $out = sys_get_temp_dir() . '/i32-selftest-db-' . bin2hex(random_bytes(4));
    $cmd = static fn(string $args): int => (function () use ($args): int {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/study.php') . ' ' . $args . ' 2>&1', $o, $code);

        return $code;
    })();
    $base = 'run --out=' . escapeshellarg($out) . ' --tag=st --levels=2 --rounds=1 --ops=4 --policies=immediate';
    t('reuse guard: first run into a fresh directory succeeds', $cmd($base) === 0);
    t('reuse guard: second run into the same directory/tag is refused', $cmd($base) !== 0);
    t('reuse guard: --resume=1 with the same configuration is accepted', $cmd($base . ' --resume=1') === 0);
    t('reuse guard: same tag with different parameters is refused even with --resume=1', $cmd(str_replace('--ops=4', '--ops=8', $base) . ' --resume=1') !== 0);
    // Fixture host log (quiet, v2) covering the run, then the real classifier writes load.json (the sampler itself is exercised by run-all.sh).
    $now = time();
    $lines = [];
    for ($t = $now - 120; $t <= $now + 10; ++$t) {
        $lines[] = gmdate('Y-m-d\TH:i:s\Z', $t) . ' foreign=10 load1=1.0 v=2';
    }
    file_put_contents($out . '/host-load.log', implode("\n", $lines) . "\n");
    writeLoadJson($out);
    $summaryFile = glob($out . '/runs/st_*.jsonl.gz')[0];
    $analysis = (function () use ($out): int {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/analyze.php') . ' --dir=' . escapeshellarg($out) . ' 2>&1', $o, $code);

        return $code;
    })();
    t('analyzer accepts the freshly produced real-MySQL evidence set', $analysis === 0);
    unlink($summaryFile);
    $analysis = (function () use ($out): int {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/analyze.php') . ' --dir=' . escapeshellarg($out) . ' 2>&1', $o, $code);

        return $code;
    })();
    t('analyzer refuses the same set after a run file is deleted', $analysis === 3);

    // Observer sensitivity: with the consumer disabled the preflight positive control MUST fail.
    $admin = connect(connectionConfig());
    $admin->exec("UPDATE performance_schema.setup_consumers SET ENABLED = 'NO' WHERE NAME = 'events_transactions_current'");
    try {
        $failedCode = $cmd('preflight --out=' . escapeshellarg($out . '/pf-off'));
    } finally {
        $admin->exec("UPDATE performance_schema.setup_consumers SET ENABLED = 'YES' WHERE NAME = 'events_transactions_current'");
    }
    t('observer sensitivity: preflight FAILS when events_transactions_current is disabled', $failedCode !== 0);
    t('observer sensitivity: preflight passes again once restored', $cmd('preflight --out=' . escapeshellarg($out . '/pf-on')) === 0);
}

echo "\n$total tests, $failures failed\n";
exit($failures === 0 ? 0 : 1);
