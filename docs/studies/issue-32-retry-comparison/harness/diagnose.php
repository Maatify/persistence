<?php

/**
 * Issue #32 study harness: SQL / index / collision-cause diagnosis (EXPLAIN, data_locks, real deadlock report).
 */

declare(strict_types=1);

namespace Maatify\Persistence\Study;

use PDO;
use PDOException;

function commandDiagnose(array $args): void
{
    $cfg = connectionConfig();
    $out = rtrim($args['out'] ?? throw new \InvalidArgumentException('--out required'), '/');
    @mkdir($out, 0777, true);
    $report = [];
    $db = dbName($cfg);

    resetEnvironment($cfg, 4);
    $a = connect($cfg);
    $b = connect($cfg);

    // 1. EXPLAIN of every statement of the scenario.
    $explain = [];
    foreach ([
        'find_owner' => Scenario::SQL_FIND_OWNER,
        'lock_product' => Scenario::SQL_LOCK_PRODUCT,
        'lock_options' => Scenario::SQL_LOCK_OPTIONS,
        'lock_values' => Scenario::SQL_LOCK_VALUES,
        'lock_variants' => Scenario::SQL_LOCK_VARIANTS,
        'lock_name' => Scenario::SQL_LOCK_NAME,
    ] as $label => $sql) {
        $literal = (string) preg_replace('/:(id|product_id)\b/', '1', $sql);
        $explain[$label] = ['sql' => $sql, 'plan' => $a->query('EXPLAIN ' . $literal)->fetchAll()];
    }
    $report['explain'] = $explain;

    // 2. Both transactions run the locking reads of two DIFFERENT, brand-new Products (no overlap by key).
    $a->beginTransaction();
    $b->beginTransaction();
    $idA = (int) $a->query('SELECT CONNECTION_ID()')->fetchColumn();
    $idB = (int) $b->query('SELECT CONNECTION_ID()')->fetchColumn();
    $started = hrtime(true);
    Scenario::lockSnapshot($a, 1);
    Scenario::lockSnapshot($b, 2);
    $report['both_locking_reads_succeeded_without_blocking_ms'] = round((hrtime(true) - $started) / 1e6, 2);

    $locks = static function (PDO $pdo) use ($db): array {
        $statement = $pdo->prepare(
            'SELECT t.trx_mysql_thread_id AS conn, l.object_name AS tbl, l.index_name AS idx, l.lock_type, l.lock_mode, l.lock_status, l.lock_data '
            . 'FROM performance_schema.data_locks l JOIN information_schema.INNODB_TRX t ON t.trx_id = l.engine_transaction_id '
            . 'WHERE l.object_schema = :db ORDER BY t.trx_mysql_thread_id, l.object_name, l.index_name, l.lock_mode, l.lock_data',
        );
        $statement->execute(['db' => $db]);

        return $statement->fetchAll();
    };
    $report['data_locks_after_locking_reads'] = ['conn_a' => $idA, 'conn_b' => $idB, 'locks' => $locks($b)];

    // 3. A now inserts its Content: it must wait for B's gap lock (insert-intention vs gap lock).
    $a->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $started = hrtime(true);
    $blocked = null;
    try {
        $now = date('Y-m-d H:i:s');
        $insert = $a->prepare(Scenario::SQL_INSERT_CONTENT);
        $insert->execute(['owner_id' => 1, 'language_code' => null, 'name' => 'op-1', 'short_description' => null, 'description' => null, 'created_at' => $now, 'updated_at' => $now]);
        $blocked = false;
    } catch (PDOException $exception) {
        $blocked = ['error' => $exception->errorInfo, 'waited_ms' => round((hrtime(true) - $started) / 1e6)];
    }
    $report['insert_while_other_holds_gap_lock'] = $blocked;
    $a->rollBack();
    $b->rollBack();
    $a = null;
    $b = null;

    // 4. Real deadlocks: two independent workers, no retry; capture InnoDB's own deadlock report.
    $report['innodb_latest_detected_deadlock'] = null;
    $control = connect($cfg);
    for ($attempt = 1; $attempt <= 10; ++$attempt) {
        $result = runOne($cfg, ['conns' => 2, 'ops' => 40, 'policy' => 'none', 'round' => $attempt, 'placement' => 'sequential', 'mode' => 'wave', 'verify' => false, 'seed' => $attempt, 'order' => 1, 'tag' => 'diagnose']);
        if ($result['summary']['client_deadlocks'] > 0) {
            $status = (string) $control->query('SHOW ENGINE INNODB STATUS')->fetch()['Status'];
            if (preg_match('/LATEST DETECTED DEADLOCK\n-+\n(.*?)\n-{10,}\nTRANSACTIONS/s', $status, $m) === 1) {
                $report['innodb_latest_detected_deadlock'] = $m[1];
            }
            $report['deadlock_sample_run'] = ['policy' => 'none', 'connections' => 2, 'ops' => 40, 'client_deadlocks' => $result['summary']['client_deadlocks'], 'server_deadlocks_delta' => $result['summary']['server_deadlocks_delta'], 'error_codes_seen' => array_values(array_unique(array_merge(...array_map(static fn(array $o): array => array_column($o['attempts'], 2), $result['ops']))))];
            break;
        }
    }

    file_put_contents($out . '/diagnosis.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fwrite(STDERR, "diagnosis written to $out/diagnosis.json\n");
}
