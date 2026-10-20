<?php

declare(strict_types=1);

/**
 * P7 - Feasibility probe of the one package-side route that does not touch session state: MariaDB's per-statement
 * `SET STATEMENT innodb_snapshot_isolation=OFF FOR <stmt>`. Evidence only; NOT a recommended implementation.
 * Checks: does it avoid 1020 for the locking read and for the range UPDATEs of moveWithinScope(), does it leak into
 * the session afterwards, is it accepted as a prepared statement, and what MySQL does with the syntax.
 */

require __DIR__ . '/lib.php';

$pdo = p47_pdo();
$other = p47_pdo();
echo "== P7 " . p47_engine($pdo) . "\n";
$T = P47_T;

$tail = "SELECT display_order FROM $T WHERE scope_id = 1 AND deleted_at IS NULL ORDER BY display_order DESC, id DESC LIMIT 1 FOR UPDATE";
$shift = "UPDATE $T SET display_order = display_order + 1 WHERE scope_id = 1 AND deleted_at IS NULL AND display_order >= 1 AND display_order < 3";

foreach (['locking read' => $tail, 'range UPDATE' => $shift] as $label => $sql) {
    foreach (['plain statement' => $sql, 'SET STATEMENT ... FOR (prepared)' => "SET STATEMENT innodb_snapshot_isolation=OFF FOR $sql"] as $form => $text) {
        p47_seed($pdo);
        $pdo->beginTransaction();
        $pdo->query("SELECT COUNT(*) FROM $T")->fetchAll();
        $other->exec("UPDATE $T SET display_order = 30 WHERE id = 3");                  // changes a row the statement visits
        $r = p47_try(static function () use ($pdo, $text): mixed {
            $s = $pdo->prepare($text);
            $s->execute();

            return $s->columnCount() > 0 ? $s->fetchColumn() : $s->rowCount();
        });
        $leak = p47_try(static fn () => $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn());
        printf("  %-13s %-34s -> %-12s session value afterwards: %s\n", $label, $form, $r, $leak);
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
$pdo->exec("DROP TABLE IF EXISTS $T");
