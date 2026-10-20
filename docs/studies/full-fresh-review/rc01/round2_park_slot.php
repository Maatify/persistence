<?php

declare(strict_types=1);

/**
 * Round 2 / F45-R01: is the parking slot actually free? Soft-deleted rows OUTSIDE the movement range,
 * integer boundaries, a competing writer, and failure at the parking step.
 */

require __DIR__ . '/lib.php';

function cell(PDO $pdo, string $candidate, int $id, int $new, string $mode = 'owned'): string
{
    $before = json_encode(rc_state($pdo));

    try {
        if ($mode === 'caller') {
            $pdo->beginTransaction();
        }

        rc_candidates()[$candidate]($pdo, $id, $new);

        if ($mode === 'caller') {
            $pdo->commit();
        }

        return 'GREEN ' . json_encode(rc_state($pdo));
    } catch (Throwable $throwable) {
        $alive = $pdo->inTransaction();
        $after = json_encode(rc_state($pdo));

        if ($alive) {
            $pdo->commit();
            $after = json_encode(rc_state($pdo));
        }

        $code = $throwable instanceof PDOException ? ($throwable->errorInfo[1] ?? '?') : get_class($throwable);

        return 'RED ' . $code . ($after === $before ? ' (state unchanged)' : ' (STATE CHANGED ' . $after . ')');
    }
}

$pdo = rc_pdo();
$cands = ['B  park+ORDER BY', 'B2 park(locked max)+ORDER BY', 'B3 park(locked max, all rows)+ORDER BY'];

echo "== 1. plain UNIQUE signed, active 1..6, soft-deleted row at a given order OUTSIDE the range of move id1 -> 4\n";
foreach ([7 => 'deleted at maxActive+1', 8 => 'deleted at maxActive+2', 100 => 'deleted far above'] as $deletedAt => $label) {
    rc_schema($pdo, true, false);

    foreach ($cands as $candidate) {
        rc_seed($pdo, [1, 2, 3, 4, 5, 6], [$deletedAt]);
        printf("  %-26s %-34s %s\n", $label, $candidate, cell($pdo, $candidate, 1, 4));
    }
}

echo "\n== 1b. same data on the active-only unique index (generated column)\n";
rc_schema($pdo, false, false, true);

foreach ($cands as $candidate) {
    rc_seed($pdo, [1, 2, 3, 4, 5, 6], [7]);
    printf("  %-26s %-34s %s\n", 'deleted at maxActive+1', $candidate, cell($pdo, $candidate, 1, 4));
}

echo "\n== 2. integer boundaries: highest active order near the column maximum, move id1 -> 3\n";
foreach ([['signed INT', false, 2147483647], ['unsigned INT', true, 4294967295]] as [$label, $unsigned, $max]) {
    rc_schema($pdo, true, $unsigned);

    foreach ([$max - 1, $max] as $top) {
        foreach (['B2 park(locked max)+ORDER BY', 'BS2 B2+savepoint', 'B3 park(locked max, all rows)+ORDER BY', 'BS3 B3+savepoint'] as $candidate) {
            rc_seed($pdo, [1, 2, 3, $top]);
            printf("  %-13s highest active=%-10d %-40s owned : %s\n", $label, $top, $candidate, cell($pdo, $candidate, 1, 3));
        }
    }
}
rc_schema($pdo, true, false);
rc_seed($pdo, [1, 2, 3, 2147483647]);
printf("  %-13s highest active=%-10d %-22s caller: %s\n", 'signed INT', 2147483647, 'B2 (no savepoint)', cell($pdo, 'B2 park(locked max)+ORDER BY', 1, 3, 'caller'));
rc_seed($pdo, [1, 2, 3, 2147483647]);
printf("  %-13s highest active=%-10d %-22s caller: %s\n", 'signed INT', 2147483647, 'BS2 (savepoint)', cell($pdo, 'BS2 B2+savepoint', 1, 3, 'caller'));
foreach (['B3 park(locked max, all rows)+ORDER BY' => 'B3 (no savepoint)', 'BS3 B3+savepoint' => 'BS3 (savepoint)'] as $candidate => $label) {
    rc_seed($pdo, [1, 2, 3, 2147483647]);
    printf("  %-13s highest active=%-10d %-22s caller: %s\n", 'signed INT', 2147483647, $label, cell($pdo, $candidate, 1, 3, 'caller'));
}

echo "\n== 3. competing plain writer targeting the parking slot while the move transaction is open\n";
$other = rc_pdo();
$other->exec('SET SESSION innodb_lock_wait_timeout = 1');
rc_schema($pdo, true, false);
rc_seed($pdo, [1, 2, 3, 4, 5, 6]);
$pdo->beginTransaction();
$max = rc_lock_scope($pdo);
rc_set($pdo, 1, $max + 1); // the parking write only
try {
    $other->exec('INSERT INTO ' . RC_T . ' (scope_id, display_order) VALUES (1, ' . ($max + 1) . ')');
    echo "  competing insert: succeeded (!)\n";
} catch (PDOException $exception) {
    echo '  competing insert while parked: error ' . ($exception->errorInfo[1] ?? '?') . " (blocked by the open transaction)\n";
}
$pdo->rollBack();
$pdo->beginTransaction();
$pdo->exec('UPDATE ' . RC_T . ' SET display_order = 7 WHERE id = 1');
$pdo->commit();
try {
    $other->exec('INSERT INTO ' . RC_T . ' (scope_id, display_order) VALUES (1, 7)');
    echo "  competing insert after commit of the slot: succeeded (!)\n";
} catch (PDOException $exception) {
    echo '  competing insert after the slot is taken: error ' . ($exception->errorInfo[1] ?? '?') . " (clean duplicate error, no corruption)\n";
}

echo "\n== 4. failure injected at the PARKING write (trigger), plain UNIQUE signed 1..6, id1 -> 4\n";
$pdo->exec('DROP TRIGGER IF EXISTS probe_rc01_fail');
$pdo->exec("CREATE TRIGGER probe_rc01_fail BEFORE UPDATE ON " . RC_T . " FOR EACH ROW BEGIN IF NEW.id = 1 AND NEW.display_order = 7 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'park'; END IF; END");

foreach (['B2 park(locked max)+ORDER BY', 'BS2 B2+savepoint'] as $candidate) {
    foreach (['owned', 'caller'] as $mode) {
        rc_seed($pdo, [1, 2, 3, 4, 5, 6]);
        printf("  %-22s %-7s %s\n", $candidate, $mode, cell($pdo, $candidate, 1, 4, $mode));
    }
}

$pdo->exec('DROP TRIGGER IF EXISTS probe_rc01_fail');
$pdo->exec('DROP TABLE IF EXISTS ' . RC_T);
