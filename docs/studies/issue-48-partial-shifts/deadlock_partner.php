<?php

declare(strict_types=1);

/**
 * Child of matrix.php (cell C10): the other side of a real InnoDB deadlock, on its own connection.
 * It makes its transaction heavy (200 inserted rows) so InnoDB picks the parent's lighter transaction as the
 * victim, locks audit row 2, prints READY, sleeps, then requests audit row 1 (held by the parent) and prints
 * ACQUIRED or FAILURE <code>. It always rolls back.
 */

require __DIR__ . '/lib.php';

$pdo = p48_pdo();
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');
$pdo->beginTransaction();

try {
    $pdo->exec(
        'INSERT INTO ' . P48_FILL . ' (payload)
         WITH RECURSIVE seq (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 200)
         SELECT "partner-heavy" FROM seq'
    );
    $pdo->query('SELECT id FROM ' . P48_AUDIT . ' WHERE id = 2 FOR UPDATE')->fetchAll();
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    usleep((int) (($argv[1] ?? '1.5') * 1_000_000));
    try {
        $pdo->query('SELECT id FROM ' . P48_AUDIT . ' WHERE id = 1 FOR UPDATE')->fetchAll();
        fwrite(STDOUT, "ACQUIRED\n");
    } catch (PDOException $e) {
        fwrite(STDOUT, 'FAILURE ' . ($e->errorInfo[1] ?? 'unknown') . "\n");
    }
    fflush(STDOUT);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
