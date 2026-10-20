<?php

declare(strict_types=1);

/**
 * P5 - Candidate remedies, each run against the stale-snapshot scenario with the SHIPPED getNextPositionForUpdate()
 * and moveWithinScope(). "expect" is the MySQL 8.4 result: next position 6 / move 1->5 gives 5,1,2,3,4.
 * Run with the engine default (no PROBE override) so that the remedy is the only change.
 */

require __DIR__ . '/lib.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

$pdo = p47_pdo();
$other = p47_pdo();
echo "== P5 " . p47_engine($pdo) . "\n";
$T = P47_T;
$manager = new ScopedOrderingManager();

$remedies = [
    'none (baseline)' => ['pre' => null, 'afterSnapshot' => null],
    'R1 SET SESSION snapshot_isolation=OFF before BEGIN' => ['pre' => 'SET SESSION innodb_snapshot_isolation = OFF', 'afterSnapshot' => null],
    'R2 SET SESSION snapshot_isolation=OFF after snapshot (mid-txn)' => ['pre' => null, 'afterSnapshot' => 'SET SESSION innodb_snapshot_isolation = OFF'],
    'R3 SET TRANSACTION ISOLATION LEVEL READ COMMITTED before BEGIN' => ['pre' => 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', 'afterSnapshot' => null],
    'R4 SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' => ['pre' => 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED', 'afterSnapshot' => null],
    'R5 scope lock taken BEFORE the first plain read' => ['pre' => null, 'afterSnapshot' => null, 'lockFirst' => true],
];

foreach ($remedies as $label => $r) {
    foreach (['getNextPositionForUpdate' => 'next', 'moveWithinScope(1->5)' => 'move'] as $api => $kind) {
        $pdo = p47_pdo();
        p47_seed($pdo);
        if ($r['pre'] !== null) {
            p47_try(static fn () => $pdo->exec($r['pre']));
        }
        $pdo->beginTransaction();
        if (!empty($r['lockFirst'])) {
            $pdo->query("SELECT id FROM $T WHERE scope_id = 1 ORDER BY display_order DESC LIMIT 1 FOR UPDATE")->fetchAll();
            $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        }
        $pdo->query("SELECT COUNT(*) FROM $T")->fetchAll();
        if ($r['afterSnapshot'] !== null) {
            p47_try(static fn () => $pdo->exec($r['afterSnapshot']));
        }
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $changeErr = p47_try(static fn () => $other->exec("INSERT INTO $T (scope_id, display_order) VALUES (1,4),(1,5)"));
        if ($changeErr !== 'ok: 2') {
            printf("  %-62s %-24s concurrent writer blocked by remedy lock (%s) - correct serialization, nothing to read\n", $label, $api, $changeErr);
            $pdo->rollBack();
            continue;
        }
        $res = p47_try(static fn () => $kind === 'next'
            ? $manager->getNextPositionForUpdate($pdo, p47_config(), 1)
            : $manager->moveWithinScope($pdo, p47_config(), 1, 1, 5));
        $state = $pdo->inTransaction() && !str_starts_with($res, 'ERR') ? p47_state($pdo) : '-';
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        printf("  %-62s %-24s %-14s %s\n", $label, $api, $res, $state);
    }
}

// R6: whole-transaction restart on 1020 by the CALLER (rollback, new snapshot, run again).
p47_seed($pdo);
$pdo->beginTransaction();
$pdo->query("SELECT COUNT(*) FROM $T")->fetchAll();
$other->exec("INSERT INTO $T (scope_id, display_order) VALUES (1,4),(1,5)");
$first = p47_try(static fn () => $manager->getNextPositionForUpdate($pdo, p47_config(), 1));
if ($pdo->inTransaction()) {
    $pdo->rollBack();
}
$pdo->beginTransaction();
$second = p47_try(static fn () => $manager->getNextPositionForUpdate($pdo, p47_config(), 1));
$pdo->rollBack();
printf("  %-62s %-24s first=%s, restarted=%s\n", 'R6 caller restarts the whole transaction on 1020', 'getNextPositionForUpdate', $first, $second);
$pdo->exec("DROP TABLE IF EXISTS $T");
