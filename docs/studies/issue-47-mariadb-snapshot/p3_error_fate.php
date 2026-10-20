<?php

declare(strict_types=1);

/**
 * P3 - Fate of the caller's transaction after 1020. Is the failure statement-level (transaction survives with its
 * earlier writes) or does the engine abort the whole transaction? Checked with an earlier marker write and with
 * the server's own view (a second connection) rather than PDO::inTransaction() alone.
 */

require __DIR__ . '/lib.php';

$pdo = p47_pdo();
$other = p47_pdo();
$other->exec('SET SESSION innodb_lock_wait_timeout = 1');
echo "== P3 " . p47_engine($pdo) . "\n";
$T = P47_T;

foreach (['prepared' => true, 'text' => false] as $mode => $prepared) {
    p47_seed($pdo);
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO $T (scope_id, display_order) VALUES (7, 1)");          // marker write inside the txn
    $pdo->query("SELECT COUNT(*) FROM $T")->fetchAll();                              // snapshot
    $other->exec("INSERT INTO $T (scope_id, display_order) VALUES (1, 4)");
    $sql = "SELECT display_order FROM $T WHERE scope_id = 1 AND deleted_at IS NULL ORDER BY display_order DESC, id DESC LIMIT 1 FOR UPDATE";
    $r = p47_try(static function () use ($pdo, $sql, $prepared): mixed {
        if ($prepared) {
            $s = $pdo->prepare($sql);
            $s->execute();

            return $s->fetchColumn();
        }

        return $pdo->query($sql)->fetchColumn();
    });
    $inTxn = $pdo->inTransaction();
    $markerOwn = $pdo->query("SELECT COUNT(*) FROM $T WHERE scope_id = 7")->fetchColumn();
    // A second statement that conflicts with the marker row, if the marker is still held by an open transaction.
    $blocked = p47_try(static fn () => $other->exec("UPDATE $T SET display_order = 2 WHERE scope_id = 7"));
    printf("  %-8s locking read -> %-9s PDO::inTransaction=%s own-view marker rows=%s other-conn UPDATE of marker row -> %s\n",
        $mode, $r, var_export($inTxn, true), $markerOwn, $blocked);
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
$pdo->exec("DROP TABLE IF EXISTS $T");

// Follow-up hazard check: after 1020, the caller (who still sees PDO::inTransaction()) writes and then rolls back.
// If the engine already ended the transaction, that write was autocommitted and survives the caller's rollBack().
$pdo = p47_pdo();
p47_seed($pdo);
$pdo->beginTransaction();
$pdo->exec("INSERT INTO $T (scope_id, display_order) VALUES (7, 1)");
$pdo->query("SELECT COUNT(*) FROM $T")->fetchAll();
$other->exec("INSERT INTO $T (scope_id, display_order) VALUES (1, 4)");
$err = p47_try(static fn () => $pdo->query("SELECT display_order FROM $T WHERE scope_id = 1 AND deleted_at IS NULL ORDER BY display_order DESC, id DESC LIMIT 1 FOR UPDATE")->fetchColumn());
$pdoSays = $pdo->inTransaction();
$pdo->exec("INSERT INTO $T (scope_id, display_order) VALUES (8, 1)");               // written AFTER the 1020
$serverSays = p47_try(static fn () => $pdo->query('SELECT @@in_transaction')->fetchColumn());
if ($pdo->inTransaction()) {
    $pdo->rollBack();
}
$survivors = $other->query("SELECT COUNT(*) FROM $T WHERE scope_id = 8")->fetchColumn();
printf("  after-1020 hazard: first=%s PDO::inTransaction=%s @@in_transaction=%s; write after the error then caller rollBack() -> row survives on other connection: %s\n",
    $err, var_export($pdoSays, true), $serverSays, $survivors === '1' || $survivors === 1 ? 'YES (was autocommitted)' : 'no (rolled back)');
$pdo->exec("DROP TABLE IF EXISTS $T");
