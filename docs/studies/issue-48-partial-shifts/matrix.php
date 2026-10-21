<?php

declare(strict_types=1);

/**
 * Failure matrix for moveWithinScope() inside a caller-owned transaction.
 * Usage: php matrix.php <real|recipe|proto> [cell-id-prefix ...]
 * Every cell: caller begins, writes a marker row (earlier caller work), calls the subject, then "carries on and commits".
 * Columns: result of the call; the caller's own view; whether the marker/transaction survived; savepoint and
 * commit/rollback statement counts issued by the call (Δ); what another connection sees after the caller's commit.
 */

require __DIR__ . '/lib.php';

$subject = $argv[1] ?? '';
if (!in_array($subject, ['real', 'recipe', 'proto', 'nested', 'reuse'], true)) {
    fwrite(STDERR, "usage: matrix.php real|recipe|proto|nested|reuse [cell prefix...]\n");
    exit(2);
}
$only = array_slice($argv, 2);
$wants = static fn (string $id): bool => $only === [] || array_filter($only, static fn (string $p): bool => str_starts_with($id, $p)) !== [];

$probe = p48_pdo();
echo '== ' . $subject . ' @ ' . p48_engine($probe) . "\n";
echo 'source: ' . str_replace(dirname(__DIR__, 3) . '/', '', (string) (new ReflectionClass(Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager::class))->getFileName()) . "\n";
$mariadb = str_contains((string) $probe->query('SELECT VERSION()')->fetchColumn(), 'MariaDB');
$probe = null;

$T_NEUTRALIZE = ['tr_neutral', 'BEFORE', 'IF NEW.id = 5 THEN SET NEW.display_order = OLD.display_order; SET NEW.updated_at = OLD.updated_at; END IF;'];
$T_AUDIT_FINAL = ['tr_audit_final', 'AFTER', 'IF NEW.id = 5 THEN UPDATE ' . P48_AUDIT . ' SET n = n + 1 WHERE id = 2; END IF;'];
$T_AUDIT_SHIFT = ['tr_audit_shift', 'AFTER', 'IF NEW.id = 3 THEN UPDATE ' . P48_AUDIT . ' SET n = n + 1 WHERE id = 2; END IF;'];

$holdAudit2 = static function (PDO $a, PDO $b, array &$ctx): void {
    $b2 = p48_pdo();
    $b2->beginTransaction();
    $b2->exec('UPDATE ' . P48_AUDIT . ' SET n = n + 1 WHERE id = 2');   // a competing transaction holds audit row 2
    $ctx['conns'][] = $b2;
};

$cells = [];

// ---- controls inside a caller-owned transaction ----
$cells[] = ['id' => 'C01-ok'];
$cells[] = ['id' => 'C02-noop', 'to' => 5];
$cells[] = ['id' => 'C03-missing', 'target' => 99];

// ---- `false` after the shifts (final update changes nothing) ----
$cells[] = ['id' => 'C04-false', 'triggers' => [$T_NEUTRALIZE]];

// ---- 1062 ----
$cells[] = ['id' => 'C05-1062shift', 'variant' => 'uniq_order'];
$cells[] = ['id' => 'C06-1062final', 'variant' => 'uniq_updated', 'stamp' => P48_STAMP_DUP];

// ---- 1205 (lock wait timeout) ----
$cells[] = ['id' => 'C07-1205final', 'triggers' => [$T_AUDIT_FINAL], 'lockWait' => 1, 'pre' => $holdAudit2];
$cells[] = ['id' => 'C08-1205shift', 'triggers' => [$T_AUDIT_SHIFT], 'lockWait' => 1, 'pre' => $holdAudit2];

// ---- 1213 (real deadlock; the caller's transaction is the victim) ----
$deadlockPre = static function (PDO $a, PDO $b, array &$ctx): void {
    $a->exec('UPDATE ' . P48_AUDIT . ' SET n = n + 1 WHERE id = 1');   // caller's earlier lock on audit row 1
    $proc = proc_open([PHP_BINARY, __DIR__ . '/deadlock_partner.php', '1.5'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv());
    $ready = fgets($pipes[1]);
    if (trim((string) $ready) !== 'READY') {
        throw new RuntimeException('partner not ready: ' . $ready . stream_get_contents($pipes[2]));
    }
    $ctx['proc'] = [$proc, $pipes];
};
$deadlockPost = static function (PDO $a, PDO $b, array &$ctx): void {
    [$proc, $pipes] = $ctx['proc'];
    $out = trim((string) stream_get_contents($pipes[1]));
    proc_close($proc);
    $ctx['note'] = 'partner=' . $out;
};
$cells[] = ['id' => 'C10-1213final', 'triggers' => [$T_AUDIT_FINAL], 'lockWait' => 20, 'pre' => $deadlockPre, 'post' => $deadlockPost];
// The caller catches the 1213 and carries on with a second move that fails with 1062 after its shifts (the forbidden pattern).
$cells[] = [
    'id' => 'C15-after-1213', 'variant' => 'uniq_updated', 'triggers' => [$T_AUDIT_FINAL], 'lockWait' => 20,
    'steps' => [[5, 2, P48_STAMP_OK], [5, 2, P48_STAMP_DUP]], 'pre' => $deadlockPre, 'post' => $deadlockPost,
];

// ---- 1020 (MariaDB innodb_snapshot_isolation=ON; on MySQL the same scenario is a control that succeeds) ----
$cells[] = [
    'id' => 'C11-1020lock',
    'snapshot' => true,
    'pre' => static function (PDO $a, PDO $b, array &$ctx): void {
        $b->exec("UPDATE " . P48_T . " SET updated_at = '2026-03-01 00:00:00' WHERE id = 1");   // scope row changed after the snapshot
    },
];
$cells[] = [
    'id' => 'C12-1020final',
    'snapshot' => true,
    'triggers' => [$T_AUDIT_FINAL],
    'pre' => static function (PDO $a, PDO $b, array &$ctx): void {
        $b->exec('UPDATE ' . P48_AUDIT . ' SET n = n + 100 WHERE id = 2');   // the trigger's row changed after the snapshot
    },
];

// ---- operation-locality: a later failing operation must not undo an earlier successful one, nor the caller's own savepoints ----
$cells[] = [
    'id' => 'C13-two-steps',
    'variant' => 'uniq_updated',
    'steps' => [[5, 2, P48_STAMP_OK], [1, 4, '2026-01-02 00:00:00']],   // 1st succeeds; 2nd shifts, then 1062 on the target write
];
$cells[] = [
    'id' => 'C14-caller-sp',
    'variant' => 'uniq_updated',
    'stamp' => P48_STAMP_DUP,
    'pre' => static function (PDO $a, PDO $b, array &$ctx): void {
        $a->exec('SAVEPOINT caller_sp');
    },
    'post' => static function (PDO $a, PDO $b, array &$ctx): void {
        $a->exec('ROLLBACK TO SAVEPOINT caller_sp');   // the caller's own savepoint must still exist
        $ctx['note'] = 'callerSavepointUsable=yes state=' . p48_classify(p48_state($a));
    },
];

// ---- savepoint command failures (fault injection through a PDO subclass; the statement is NOT executed) ----
if (in_array($subject, ['proto', 'recipe', 'reuse'], true)) {
    $cells[] = ['id' => 'S1-sp-false', 'faulty' => true, 'faults' => [['exec', '/^SAVEPOINT /', 'false']]];
    $cells[] = ['id' => 'S2-sp-throws', 'faulty' => true, 'faults' => [['exec', '/^SAVEPOINT /', 'throw']]];
    $cells[] = [
        'id' => 'S3-1062+rt-false', 'faulty' => true, 'variant' => 'uniq_updated', 'stamp' => P48_STAMP_DUP,
        'faults' => [['exec', '/^ROLLBACK TO SAVEPOINT/', 'false']],
    ];
    $cells[] = [
        'id' => 'S4-orig+rt-throws', 'faulty' => true,
        'faults' => [['prepare', '/SET `display_order` = :new_order_case/', 'runtime'], ['exec', '/^ROLLBACK TO SAVEPOINT/', 'throw']],
    ];
    $cells[] = [
        'id' => 'S5-false+rt-false', 'faulty' => true, 'triggers' => [$T_NEUTRALIZE],
        'faults' => [['exec', '/^ROLLBACK TO SAVEPOINT/', 'false']],
    ];
    $cells[] = ['id' => 'S6-rel-false', 'faulty' => true, 'faults' => [['exec', '/^RELEASE SAVEPOINT/', 'false']]];
    $cells[] = ['id' => 'S7-rel-throws', 'faulty' => true, 'faults' => [['exec', '/^RELEASE SAVEPOINT/', 'throw']]];
}

// ---- package-owned (no caller transaction) controls ----
$cells[] = ['id' => 'O1-ok', 'owned' => true];
$cells[] = ['id' => 'O2-false', 'owned' => true, 'triggers' => [$T_NEUTRALIZE]];
$cells[] = ['id' => 'O3-1062final', 'owned' => true, 'variant' => 'uniq_updated', 'stamp' => P48_STAMP_DUP];

foreach ($cells as $cell) {
    if ($wants($cell['id'])) {
        echo p48_cell($subject, $cell) . "\n";
    }
}
