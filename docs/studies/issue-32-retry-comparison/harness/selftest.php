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
        'active_us' => 1000, 'wall_us' => 2000, 'wave_active_us' => [], 'ok' => $ops, 'exhausted' => 0, 'errors' => 0, 'invalid' => 0, 'harness_sha256' => 'h1', 'mysql_version' => '8.0.46',
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

function makeSet(array $overrides = [], int $rounds = 2): string
{
    $dir = sys_get_temp_dir() . '/i32-selftest-' . bin2hex(random_bytes(4));
    mkdir($dir . '/runs', 0777, true);
    file_put_contents($dir . '/manifest.json', json_encode(['cells' => ['t' => ['levels' => [2], 'rounds' => $rounds, 'ops' => 4, 'placement' => 'sequential', 'mode' => 'wave', 'policies' => ['immediate', 'hybrid'], 'verify' => false]]]));
    for ($round = 1; $round <= $rounds; ++$round) {
        foreach (['immediate', 'hybrid'] as $policy) {
            writeRunFile($dir, 't', 2, $round, $policy, $overrides[$round . $policy] ?? []);
        }
    }

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
unlink($dir . '/manifest.json');
t('analyzer: missing manifest is rejected', $has(validateEvidence($dir, false), 'manifest.json missing'));

$dir = makeSet(['1immediate' => ['ops' => static function (array $lines): array {
    foreach ($lines as &$l) {
        $l['attempts'] = array_map(static fn(array $a): array => array_slice($a, 0, 4), $l['attempts']);
    }

    return $lines;
}]]);
t('analyzer: legacy attempt records (no SQLSTATE) are rejected unless --allow-legacy=1', $has(validateEvidence($dir, false), 'no SQLSTATE') && !$has(validateEvidence($dir, true), 'no SQLSTATE'));

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
