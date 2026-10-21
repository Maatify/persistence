<?php

declare(strict_types=1);

/**
 * S5 - Lock footprint and engine savepoint semantics relevant to the design.
 *  L1  raw SQL: is a row lock taken AFTER a savepoint released by ROLLBACK TO SAVEPOINT? (InnoDB keeps it.)
 *  L2  after a move that failed and was undone by the savepoint, are the scope rows still locked by the caller (real vs prototype)?
 *  L3  after a successful move, are the scope rows locked (real vs prototype)? The documented "locks held until the owning transaction ends" must not change.
 * "locked" = a second connection with innodb_lock_wait_timeout=1 cannot SELECT ... FOR UPDATE the row.
 */

require __DIR__ . '/lib.php';

$a = p48_pdo();
echo '== S5 ' . p48_engine($a) . "\n";

$locked = static function (int $id): string {
    $b = p48_pdo();
    $b->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $b->beginTransaction();
    try {
        $b->query('SELECT id FROM ' . P48_T . ' WHERE id = ' . $id . ' FOR UPDATE')->fetchAll();

        return 'free';
    } catch (PDOException $e) {
        return 'locked(' . ($e->errorInfo[1] ?? '?') . ')';
    } finally {
        $b->rollBack();
    }
};

foreach (['SELECT ... FOR UPDATE' => 'SELECT id FROM ' . P48_T . ' WHERE id = 6 FOR UPDATE', 'UPDATE' => 'UPDATE ' . P48_T . ' SET display_order = display_order WHERE id = 6', 'UPDATE changing the row' => 'UPDATE ' . P48_T . ' SET display_order = display_order + 10 WHERE id = 6'] as $label => $sql) {
    p48_schema($a);
    $a->beginTransaction();
    $a->exec('SAVEPOINT s');
    $a->query($sql)->fetchAll();
    $a->exec('ROLLBACK TO SAVEPOINT s');
    echo '  L1 ' . $label . ' after SAVEPOINT, then ROLLBACK TO SAVEPOINT: row 6 is ' . $locked(6) . "\n";
    $a->rollBack();
}

foreach (['real', 'proto', 'recipe'] as $subject) {
    p48_schema($a, 'uniq_updated');
    $a->beginTransaction();
    try {
        p48_subject($subject)($a, P48_MOVE_ID, P48_MOVE_TO, P48_STAMP_DUP);   // 1062 after the shifts
    } catch (PDOException) {
    }
    printf("  L2 %-5s after failed move (state %s): scope rows 1,3,5 are %s/%s/%s; other scope row 6 is %s\n", $subject, p48_classify(p48_state($a)), $locked(1), $locked(3), $locked(5), $locked(6));
    $a->rollBack();

    p48_schema($a);
    $a->beginTransaction();
    p48_subject($subject)($a, P48_MOVE_ID, P48_MOVE_TO, P48_STAMP_OK);
    printf("  L3 %-5s after successful move: scope rows 1,3,5 are %s/%s/%s; other scope row 6 is %s\n", $subject, $locked(1), $locked(3), $locked(5), $locked(6));
    $a->rollBack();
}
p48_drop($a);
