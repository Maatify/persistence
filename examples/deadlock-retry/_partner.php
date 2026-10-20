<?php

declare(strict_types=1);

/**
 * Helper process for the deadlock-retry examples (not package API, not an example).
 *
 * On its own connection it opens a transaction, makes it heavy (200 inserted
 * rows) so InnoDB picks the example's lighter transaction as the deadlock
 * victim, locks row 2 and prints "READY <connection id>". After a line on stdin
 * it requests row 1, prints "ACQUIRED" once it gets it (after the victim was
 * rolled back) or "FAILURE <driver code>", and always rolls back.
 *
 * Usage: php _partner.php <table>
 */

require __DIR__ . '/_support.php';

$table = $argv[1] ?? '';

if (preg_match('/^maa_example_deadlock_retry_[0-9a-f]{12}$/', $table) !== 1) {
    fwrite(STDERR, "Usage: _partner.php <example table>\n");

    exit(2);
}

$pdo = example_connect();
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');
$pdo->beginTransaction();

try {
    $pdo->exec(
        'INSERT INTO ' . $table . ' (label)
         WITH RECURSIVE seq (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 200)
         SELECT "partner-heavy" FROM seq',
    );
    example_lock_row($pdo, $table, 2);

    $connectionId = $pdo->query('SELECT CONNECTION_ID()');
    echo 'READY ' . ($connectionId instanceof PDOStatement ? $connectionId->fetchColumn() : '?') . PHP_EOL;
    fflush(STDOUT);
    fgets(STDIN);

    try {
        example_lock_row($pdo, $table, 1);
        echo 'ACQUIRED' . PHP_EOL;
    } catch (PDOException $exception) {
        echo 'FAILURE ' . (example_error_code($exception) ?? 'unknown') . PHP_EOL;
    }

    fflush(STDOUT);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
