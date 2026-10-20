<?php

declare(strict_types=1);

/**
 * P2 - Root cause at SQL level. For each statement shape, run it as the first statement after a stale snapshot,
 * under each kind of concurrent change, and record whether the engine raises 1020.
 */

require __DIR__ . '/lib.php';

$pdo = p47_pdo();
$other = p47_pdo();
$other->exec('SET SESSION innodb_lock_wait_timeout = 1');
echo "== P2 " . p47_engine($pdo) . "\n";

$T = P47_T;
$changes = [
    'other INSERT (1,4)' => ["INSERT INTO $T (scope_id, display_order) VALUES (1,4)"],
    'other UPDATE row id=3' => ["UPDATE $T SET display_order = 30 WHERE id = 3"],
    'other soft-DELETE id=3' => ["UPDATE $T SET deleted_at = NOW() WHERE id = 3"],
    'other INSERT other scope (2,9)' => ["INSERT INTO $T (scope_id, display_order) VALUES (2,9)"],
    'NO concurrent change' => [],
];
$statements = [
    'FOR UPDATE tail (DESC LIMIT 1)' => "SELECT display_order FROM $T WHERE scope_id = 1 AND deleted_at IS NULL ORDER BY display_order DESC, id DESC LIMIT 1 FOR UPDATE",
    'LOCK IN SHARE MODE tail' => "SELECT display_order FROM $T WHERE scope_id = 1 AND deleted_at IS NULL ORDER BY display_order DESC, id DESC LIMIT 1 LOCK IN SHARE MODE",
    'FOR UPDATE whole scope (ASC)' => "SELECT id, display_order FROM $T WHERE scope_id = 1 AND deleted_at IS NULL ORDER BY display_order, id FOR UPDATE",
    'FOR UPDATE id=1 (untouched row)' => "SELECT display_order FROM $T WHERE id = 1 FOR UPDATE",
    'FOR UPDATE NOWAIT tail' => "SELECT display_order FROM $T WHERE scope_id = 1 AND deleted_at IS NULL ORDER BY display_order DESC, id DESC LIMIT 1 FOR UPDATE NOWAIT",
    'FOR UPDATE SKIP LOCKED tail' => "SELECT display_order FROM $T WHERE scope_id = 1 AND deleted_at IS NULL ORDER BY display_order DESC, id DESC LIMIT 1 FOR UPDATE SKIP LOCKED",
    'UPDATE untouched row id=1' => "UPDATE $T SET display_order = display_order WHERE id = 1",
    'UPDATE scope range (shift)' => "UPDATE $T SET display_order = display_order + 1 WHERE scope_id = 1 AND deleted_at IS NULL AND display_order >= 1 AND display_order < 3",
    'plain INSERT (1,99)' => "INSERT INTO $T (scope_id, display_order) VALUES (1,99)",
    'plain SELECT MAX (non-locking)' => "SELECT MAX(display_order) FROM $T WHERE scope_id = 1",
];

printf("%-32s", 'statement \\ concurrent change');
foreach (array_keys($changes) as $c) {
    printf(" | %-30s", $c);
}
echo "\n";

foreach ($statements as $label => $sql) {
    printf("%-32s", $label);
    foreach ($changes as $change) {
        p47_seed($pdo);
        $pdo->beginTransaction();
        $pdo->query("SELECT COUNT(*) FROM $T")->fetchAll();
        foreach ($change as $c) {
            $other->exec($c);
        }
        $r = p47_try(static function () use ($pdo, $sql): mixed {
            $s = $pdo->query($sql);
            $v = $s->fetchAll(PDO::FETCH_NUM);
            $s->closeCursor();

            return count($v) . ' row(s)';
        });
        printf(" | %-30s", $r);
        $pdo->rollBack();
    }
    echo "\n";
}

$pdo->exec("DROP TABLE IF EXISTS {$T}_aux");
$pdo->exec("CREATE TABLE {$T}_aux (id INT PRIMARY KEY, v INT) ENGINE=InnoDB");
$pdo->exec("INSERT INTO {$T}_aux VALUES (1, 1)");

// Locking read as the FIRST statement (no snapshot yet) while another connection commits before it.
p47_seed($pdo);
$pdo->beginTransaction();
$other->exec("INSERT INTO $T (scope_id, display_order) VALUES (1,4)");
echo 'first-statement FOR UPDATE tail, change committed after BEGIN but before it: '
    . p47_try(static fn () => $pdo->query($statements['FOR UPDATE tail (DESC LIMIT 1)'])->fetchColumn()) . "\n";
// Does a locking read as the first statement establish a read view? Commit a change to an UNLOCKED row,
// then locking-read that row in the same transaction.
$other->exec("UPDATE {$T}_aux SET v = 2 WHERE id = 1");
echo '  ...then FOR UPDATE of an unrelated row changed by a commit after the first locking read: '
    . p47_try(static fn () => $pdo->query("SELECT v FROM {$T}_aux WHERE id = 1 FOR UPDATE")->fetchColumn()) . "\n";
$pdo->rollBack();

$pdo->exec("DROP TABLE IF EXISTS $T");
$pdo->exec("DROP TABLE IF EXISTS {$T}_aux");
