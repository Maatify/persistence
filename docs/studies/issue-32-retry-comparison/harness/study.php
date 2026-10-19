<?php

/**
 * Issue #32 study harness: orchestrator.
 *
 *   php study.php run      --out=DIR [--levels=2,4,8,16] [--rounds=10] [--ops=400] [--placement=sequential|shuffled]
 *                          [--mode=wave|free] [--policies=immediate,hybrid] [--verify=0|1] [--tag=NAME]
 *   php study.php diagnose --out=DIR
 *   php study.php env      --out=DIR
 *
 * Connection: STUDY_MYSQL_DSN / STUDY_MYSQL_USER / STUDY_MYSQL_PASSWORD (see README.md).
 */

declare(strict_types=1);

namespace Maatify\Persistence\Study;

require __DIR__ . '/lib.php';

use PDO;

const BARRIER_LEAD_NS = 3_000_000;

function parseArgs(array $argv): array
{
    $args = ['_' => $argv[1] ?? 'help'];
    foreach (array_slice($argv, 2) as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) === 1) {
            $args[$m[1]] = $m[2];
        }
    }

    return $args;
}

function dbName(array $cfg): string
{
    preg_match('/dbname=([^;]+)/', $cfg['dsn'], $m);

    return $m[1];
}

function dsnWithoutDb(array $cfg): string
{
    return (string) preg_replace('/dbname=[^;]+;?/', '', $cfg['dsn']);
}

/** Drop and recreate the study database from the verbatim php-product schema, then insert $products Products. */
function resetEnvironment(array $cfg, int $products): void
{
    $admin = connect(['dsn' => dsnWithoutDb($cfg)] + $cfg, true);
    $db = dbName($cfg);
    $admin->exec('DROP DATABASE IF EXISTS `' . $db . '`');
    $admin->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->exec('USE `' . $db . '`');
    $admin->exec((string) file_get_contents(__DIR__ . '/../schema/php-product-schema.sql'));

    $now = date('Y-m-d H:i:s');
    $rows = [];
    for ($i = 1; $i <= $products; ++$i) {
        $rows[] = sprintf("('P-%05d', 'active', '%s', '%s')", $i, $now, $now);
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        $admin->exec('INSERT INTO `maa_product_products` (`code`, `status`, `created_at`, `updated_at`) VALUES ' . implode(',', $chunk));
    }
}

function deadlockCounter(PDO $pdo): int
{
    $pdo->exec("SET GLOBAL innodb_monitor_enable = 'lock_deadlocks'");

    return (int) $pdo->query("SELECT `COUNT` FROM information_schema.INNODB_METRICS WHERE NAME = 'lock_deadlocks'")->fetchColumn();
}

/** @return array<string,mixed> */
function captureEnvironment(array $cfg): array
{
    $pdo = connect($cfg);
    $variables = [];
    foreach ($pdo->query("SHOW VARIABLES WHERE Variable_name IN ('version','version_comment','version_compile_os','version_compile_machine','transaction_isolation','innodb_deadlock_detect','innodb_lock_wait_timeout','innodb_flush_log_at_trx_commit','innodb_buffer_pool_size','innodb_print_all_deadlocks','autocommit','innodb_autoinc_lock_mode','innodb_thread_concurrency','max_connections','sql_mode','character_set_server','collation_server','innodb_rollback_on_timeout','innodb_adaptive_hash_index','sync_binlog','log_bin','innodb_page_size','innodb_log_file_size','innodb_redo_log_capacity')") as $row) {
        $variables[$row['Variable_name']] = $row['Value'];
    }
    $tables = [];
    foreach (['maa_product_products', 'maa_product_product_contents', 'maa_product_options', 'maa_product_option_values', 'maa_product_variants'] as $table) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch();
        $tables[$table] = $create['Create Table'] ?? null;
    }
    $container = envOr('STUDY_DOCKER_CONTAINER', '');
    $docker = null;
    if ($container !== '') {
        $docker = [
            'inspect_image' => trim((string) shell_exec('docker inspect --format "{{.Config.Image}} {{.Image}}" ' . escapeshellarg($container) . ' 2>/dev/null')),
            'host' => trim((string) shell_exec('docker info --format "{{.NCPU}} CPUs, {{.MemTotal}} bytes memory, {{.OperatingSystem}}, {{.KernelVersion}}" 2>/dev/null')),
        ];
    }

    return [
        'captured_at' => date('c'),
        'mysql_variables' => $variables,
        'tables' => $tables,
        'docker' => $docker,
        'php' => PHP_VERSION . ' ' . PHP_OS . ' ' . php_uname('m'),
        'host_os' => php_uname(),
        'repo_commit' => trim((string) shell_exec('git -C ' . escapeshellarg(REPO_ROOT) . ' rev-parse HEAD 2>/dev/null')),
        'schema_sha256' => hash_file('sha256', __DIR__ . '/../schema/php-product-schema.sql'),
        'schema_source' => 'Maatify/php-product@77d6aafbb48ff37e2ae07a24230b838848799ebd schema/product.sql (blob 753a5900d3ee6f06a91afce132d839171762e4a1)',
    ];
}

/**
 * @param array<string,mixed> $p conns, ops, policy, round, placement, mode, verify, seed, order
 * @return array{summary:array<string,mixed>,ops:list<array<string,mixed>>}
 */
function runOne(array $cfg, array $p): array
{
    $conns = (int) $p['conns'];
    $total = (int) $p['ops'];
    if ($total % $conns !== 0) {
        throw new \InvalidArgumentException('ops must be divisible by connections');
    }
    resetEnvironment($cfg, $total);
    $control = connect($cfg);
    $deadlocksBefore = deadlockCounter($control);

    $productOrder = range(1, $total);
    if ($p['placement'] === 'shuffled') {
        mt_srand((int) $p['round'] * 7919 + $conns);
        for ($i = $total - 1; $i > 0; --$i) {
            $j = mt_rand(0, $i);
            [$productOrder[$i], $productOrder[$j]] = [$productOrder[$j], $productOrder[$i]];
        }
    }

    $workers = [];
    for ($w = 0; $w < $conns; ++$w) {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/stderr', 'a']],
            $pipes,
        );
        fwrite($pipes[0], json_encode([
            'connection' => $cfg,
            'policy' => $p['policy'],
            'seed' => (int) $p['seed'] * 131 + $w,
            'verify_server_state' => (bool) $p['verify'],
        ]) . "\n");
        fflush($pipes[0]);
        $workers[] = ['proc' => $process, 'in' => $pipes[0], 'out' => $pipes[1], 'ready' => null];
    }
    foreach ($workers as $w => $worker) {
        $workers[$w]['ready'] = json_decode((string) fgets($worker['out']), true, 512, JSON_THROW_ON_ERROR);
    }

    $allOps = [];
    $waveActiveUs = [];
    $startNs = hrtime(true);
    $activeUs = 0;
    if ($p['mode'] === 'wave') {
        $waves = intdiv($total, $conns);
        for ($wave = 0; $wave < $waves; ++$wave) {
            $t0 = hrtime(true) + BARRIER_LEAD_NS;
            foreach ($workers as $w => $worker) {
                $product = $productOrder[$wave * $conns + $w];
                fwrite($worker['in'], json_encode(['cmd' => 'run', 't0_ns' => $t0, 'ops' => [[$product, $product]]]) . "\n");
                fflush($worker['in']);
            }
            $waveEnd = $t0;
            foreach ($workers as $w => $worker) {
                $reply = json_decode((string) fgets($worker['out']), true, 512, JSON_THROW_ON_ERROR);
                foreach ($reply['results'] as $result) {
                    $result['worker'] = $w;
                    $result['wave'] = $wave;
                    $waveEnd = max($waveEnd, $result['end_ns']);
                    unset($result['end_ns']);
                    $allOps[] = $result;
                }
            }
            $waveActiveUs[] = intdiv($waveEnd - $t0, 1000);
            $activeUs += intdiv($waveEnd - $t0, 1000);
        }
    } else {
        $t0 = hrtime(true) + BARRIER_LEAD_NS * 3;
        $end = $t0;
        foreach ($workers as $w => $worker) {
            $ops = [];
            for ($i = $w; $i < $total; $i += $conns) {
                $ops[] = [$productOrder[$i], $productOrder[$i]];
            }
            fwrite($worker['in'], json_encode(['cmd' => 'run', 't0_ns' => $t0, 'ops' => $ops]) . "\n");
            fflush($worker['in']);
        }
        foreach ($workers as $w => $worker) {
            $reply = json_decode((string) fgets($worker['out']), true, 512, JSON_THROW_ON_ERROR);
            foreach ($reply['results'] as $result) {
                $result['worker'] = $w;
                $result['wave'] = null;
                $end = max($end, $result['end_ns']);
                unset($result['end_ns']);
                $allOps[] = $result;
            }
        }
        $activeUs = intdiv($end - $t0, 1000);
    }
    $wallUs = intdiv(hrtime(true) - $startNs, 1000);

    // ---- integrity checks (workers are still connected and idle) ----
    // performance_schema is exact; INNODB_TRX is cached for 100 ms, so it is read after the cache expired.
    $activePs = $control->query("SELECT THREAD_ID, STATE FROM performance_schema.events_transactions_current WHERE STATE = 'ACTIVE' AND THREAD_ID <> PS_CURRENT_THREAD_ID()")->fetchAll();
    usleep(300_000);
    $openTrx = $control->query('SELECT trx_id, trx_state, trx_mysql_thread_id, trx_query, trx_started, trx_rows_locked, trx_rows_modified FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id <> CONNECTION_ID()')->fetchAll();
    $trxOpen = count($openTrx) + count($activePs);
    $deadlocksAfter = deadlockCounter($control);
    $stored = $control->query('SELECT product_id, name FROM maa_product_product_contents')->fetchAll();
    $storedByProduct = [];
    $nameMismatch = 0;
    foreach ($stored as $row) {
        $storedByProduct[(int) $row['product_id']] = ($storedByProduct[(int) $row['product_id']] ?? 0) + 1;
        if ($row['name'] !== 'op-' . $row['product_id']) {
            ++$nameMismatch;
        }
    }
    $okProducts = [];
    $clientDeadlocks = 0;
    $callbackMismatch = 0;
    $flags = [];
    $otherErrors = [];
    foreach ($allOps as $op) {
        if ($op['outcome'] === 'ok') {
            $okProducts[$op['product']] = true;
        }
        foreach ($op['attempts'] as $attempt) {
            if ($attempt[2] === 1213) {
                ++$clientDeadlocks;
            }
        }
        if ($op['callback_runs'] !== count($op['attempts'])) {
            ++$callbackMismatch;
        }
        foreach ($op['flags'] as $flag) {
            $flags[] = $op['op'] . ':' . $flag;
        }
        if ($op['other_error'] !== null) {
            $otherErrors[] = $op['op'] . ':' . $op['other_error'];
        }
    }
    $duplicateProducts = count(array_filter($storedByProduct, static fn(int $c): bool => $c > 1));
    $storedSet = array_fill_keys(array_keys($storedByProduct), true);
    $missing = array_keys(array_diff_key($okProducts, $storedSet));
    $unexpected = array_keys(array_diff_key($storedSet, $okProducts));

    $workerMeta = array_map(static fn(array $w): array => $w['ready'], $workers);
    foreach ($workers as $worker) {
        fwrite($worker['in'], json_encode(['cmd' => 'quit']) . "\n");
        fclose($worker['in']);
        fclose($worker['out']);
        proc_close($worker['proc']);
    }

    $count = static fn(string $outcome): int => count(array_filter($allOps, static fn(array $o): bool => $o['outcome'] === $outcome));

    $summary = [
        'type' => 'summary',
        'tag' => $p['tag'],
        'policy' => $p['policy'],
        'connections' => $conns,
        'ops' => $total,
        'round' => $p['round'],
        'order_in_round' => $p['order'],
        'placement' => $p['placement'],
        'mode' => $p['mode'],
        'verify_server_state' => (bool) $p['verify'],
        'started_at' => date('c'),
        'active_us' => $activeUs,
        'wall_us' => $wallUs,
        'wave_active_us' => $waveActiveUs,
        'ok' => $count('ok'),
        'exhausted' => $count('exhausted'),
        'errors' => $count('error'),
        'client_deadlocks' => $clientDeadlocks,
        'server_deadlocks_delta' => $deadlocksAfter - $deadlocksBefore,
        'integrity' => [
            'contents_rows' => count($stored),
            'ok_ops' => $count('ok'),
            'rows_match_ok_ops' => $missing === [] && $unexpected === [] && count($stored) === $count('ok'),
            'ok_ops_without_row' => $missing,
            'rows_without_ok_op' => $unexpected,
            'duplicate_product_rows' => $duplicateProducts,
            'name_mismatch_rows' => $nameMismatch,
            'callback_runs_ne_attempts' => $callbackMismatch,
            'server_open_transactions_after_run' => $trxOpen,
            'server_open_transactions_detail' => $openTrx,
            'flags' => $flags,
            'other_errors' => $otherErrors,
            'all_isolation_repeatable_read' => !in_array(false, array_map(static fn(array $m): bool => $m['isolation'] === 'REPEATABLE-READ', $workerMeta), true),
        ],
    ];

    return ['summary' => $summary, 'ops' => $allOps];
}

function writeRun(string $file, array $result): void
{
    $gz = gzopen($file, 'wb9');
    foreach ($result['ops'] as $op) {
        gzwrite($gz, json_encode(['type' => 'op'] + $op) . "\n");
    }
    gzwrite($gz, json_encode($result['summary']) . "\n");
    gzclose($gz);
}

function commandRun(array $args): void
{
    $cfg = connectionConfig();
    $out = rtrim($args['out'] ?? throw new \InvalidArgumentException('--out required'), '/');
    @mkdir($out . '/runs', 0777, true);
    $levels = array_map('intval', explode(',', $args['levels'] ?? '2,4,8,16'));
    $rounds = (int) ($args['rounds'] ?? 10);
    $ops = (int) ($args['ops'] ?? 400);
    $placement = $args['placement'] ?? 'sequential';
    $mode = $args['mode'] ?? 'wave';
    $policies = explode(',', $args['policies'] ?? 'immediate,hybrid');
    $verify = ($args['verify'] ?? '0') === '1';
    $tag = $args['tag'] ?? ($placement . '-' . $mode);

    if (!is_file($out . '/environment.json')) {
        file_put_contents($out . '/environment.json', json_encode(captureEnvironment($cfg), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    foreach ($levels as $conns) {
        for ($round = 1; $round <= $rounds; ++$round) {
            // Order balancing: odd rounds run policies in the given order, even rounds reversed.
            $order = $round % 2 === 1 ? $policies : array_reverse($policies);
            foreach ($order as $position => $policy) {
                $file = sprintf('%s/runs/%s_c%02d_r%02d_%s.jsonl.gz', $out, $tag, $conns, $round, $policy);
                if (is_file($file)) {
                    continue;
                }
                $result = runOne($cfg, [
                    'conns' => $conns, 'ops' => $ops, 'policy' => $policy, 'round' => $round, 'placement' => $placement,
                    'mode' => $mode, 'verify' => $verify, 'seed' => $round * 1000 + $conns, 'order' => $position + 1, 'tag' => $tag,
                ]);
                writeRun($file, $result);
                $s = $result['summary'];
                fwrite(STDERR, sprintf(
                    "[%s c=%d r=%d %-9s #%d] ok=%d exhausted=%d err=%d deadlocks(client/server)=%d/%d active=%.2fs integrity=%s\n",
                    $tag, $conns, $round, $policy, $position + 1, $s['ok'], $s['exhausted'], $s['errors'],
                    $s['client_deadlocks'], $s['server_deadlocks_delta'], $s['active_us'] / 1e6,
                    $s['integrity']['rows_match_ok_ops'] && $s['integrity']['server_open_transactions_after_run'] === 0 ? 'OK' : 'FAIL',
                ));
            }
        }
    }
}

require_once __DIR__ . '/diagnose.php';

$args = parseArgs($argv);
switch ($args['_']) {
    case 'run':
        commandRun($args);
        break;
    case 'diagnose':
        commandDiagnose($args);
        break;
    case 'env':
        $out = rtrim($args['out'] ?? '.', '/');
        @mkdir($out, 0777, true);
        file_put_contents($out . '/environment.json', json_encode(captureEnvironment(connectionConfig()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        break;
    default:
        fwrite(STDERR, "usage: php study.php run|diagnose|env --out=DIR [...]\n");
        exit(2);
}
