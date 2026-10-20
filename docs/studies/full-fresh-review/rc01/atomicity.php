<?php

declare(strict_types=1);

/**
 * RC-02 on top of the RC-01 candidates: what is left in a CALLER-OWNED transaction when a move fails midway?
 * Scenarios: (1) trigger fails the final target write, (2) trigger fails a shift write,
 * (3) organic 1062: a move crossing a soft-deleted row on a plain UNIQUE index.
 */

require __DIR__ . '/lib.php';

$pdo = rc_pdo();
$pdo->exec('DROP TRIGGER IF EXISTS probe_rc01_fail');

function trigger(PDO $pdo, string $condition): void
{
    $pdo->exec('DROP TRIGGER IF EXISTS probe_rc01_fail');
    $pdo->exec("CREATE TRIGGER probe_rc01_fail BEFORE UPDATE ON " . RC_T . " FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected'; END IF; END");
}

/** @return array{string, string} */
function run_case(PDO $pdo, string $candidate, int $id, int $new): array
{
    $before = json_encode(rc_state($pdo));
    $pdo->beginTransaction();

    try {
        rc_candidates()[$candidate]($pdo, $id, $new);
        $outcome = 'no exception';
    } catch (Throwable $throwable) {
        $outcome = 'threw ' . ($throwable instanceof PDOException ? ($throwable->errorInfo[1] ?? '?') : get_class($throwable));
    }

    $inside = json_encode(rc_state($pdo));
    $alive = $pdo->inTransaction();
    $pdo->commit(); // the "caller catches and carries on" path
    $after = json_encode(rc_state($pdo));

    return [$outcome . ' | txn alive: ' . var_export($alive, true), 'before ' . $before . ' -> inside ' . $inside . ' -> after caller COMMIT ' . $after . ($before === $after ? '   [RESTORED]' : '   [PARTIAL/CHANGED]')];
}

$scenarios = [
    ['1 final write fails (injected)  | UNIQUE signed, 1..6, move id1 -> 4', true, [1, 2, 3, 4, 5, 6], [], "NEW.id = 1 AND NEW.display_order = 4", 1, 4],
    ['2 a shift write fails (injected)| UNIQUE signed, 1..6, move id1 -> 4', true, [1, 2, 3, 4, 5, 6], [], "NEW.display_order = 2 AND OLD.display_order = 3", 1, 4],
    ['3 organic 1062: soft-deleted row | UNIQUE signed, active 1,2,4,5,6 deleted 3, move id1 -> 5', true, [1, 2, 4, 5, 6], [3], 'FALSE', 1, 5],
];

foreach ($scenarios as [$title, $unique, $active, $deleted, $condition, $id, $new]) {
    echo "\n== Scenario $title\n";
    rc_schema($pdo, $unique, false);

    foreach (['B  park+ORDER BY', 'BS park+ORDER BY+savepoint'] as $candidate) {
        rc_seed($pdo, $active, $deleted);
        trigger($pdo, $condition);
        [$head, $detail] = run_case($pdo, $candidate, $id, $new);
        printf("  %-28s %s\n  %-28s %s\n", $candidate, $head, '', $detail);
    }
}

// Reference: the shipped algorithm on a NON-unique table (what RC-02 describes today).
echo "\n== Reference: S0 shipped, NON-unique, 1..6, move id1 -> 4, final write fails (injected)\n";
rc_schema($pdo, false, false);
rc_seed($pdo, [1, 2, 3, 4, 5, 6]);
trigger($pdo, 'NEW.id = 1 AND NEW.display_order = 4');
[$head, $detail] = run_case($pdo, 'S0 shipped', 1, 4);
printf("  %-28s %s\n  %-28s %s\n", 'S0 shipped', $head, '', $detail);

$pdo->exec('DROP TRIGGER IF EXISTS probe_rc01_fail');
$pdo->exec('DROP TABLE IF EXISTS ' . RC_T);
