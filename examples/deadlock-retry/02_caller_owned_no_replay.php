<?php

declare(strict_types=1);

/**
 * Example 02 - inside a caller-owned transaction nothing is replayed.
 *
 * When a transaction is already active, the runners only participate
 * (PdoDeadlockRetryTransactionRunner) or use an operation-local savepoint
 * (PdoDeadlockRetrySavepointTransactionRunner). They never replay anything
 * there, whatever maxDeadlockRetries says: the transaction belongs to the
 * caller, and InnoDB has already rolled ALL of it back when it reports a
 * deadlock, including work done before the runner was called.
 *
 * What the script proves against real MySQL:
 *   - The callback runs once; the original PDOException (1213) propagates.
 *   - The caller's whole transaction is gone, including the row it wrote
 *     before calling the runner. Retrying is the caller's decision: re-run the
 *     WHOLE caller transaction, never only the failed operation.
 *   - pdo_mysql may keep reporting inTransaction() === true right after the
 *     deadlock (it updates that flag from successful server replies); after the
 *     next successful statement it reports false.
 *
 * Run: php examples/deadlock-retry/02_caller_owned_no_replay.php
 */

require __DIR__ . '/_support.php';

use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner;

example_with_table(static function (PDO $pdo, string $table): void {
    $observer = example_connect();
    $partner = ExampleDeadlockPartner::start($table); // holds row 2

    $savepoints = new PdoDeadlockRetrySavepointTransactionRunner($pdo, maxDeadlockRetries: 3);
    $calls = 0;

    $pdo->beginTransaction(); // caller-owned
    $pdo->prepare('INSERT INTO ' . $table . ' (label) VALUES ("caller-before")')->execute();

    $deadlock = null;

    try {
        $savepoints->run(function () use ($pdo, $table, $observer, $partner, &$calls): void {
            $calls++;
            example_lock_row($pdo, $table, 1);
            $partner->queueBehindRowOne($observer);
            example_lock_row($pdo, $table, 2); // PDOException 1213: the caller's transaction is the victim
        });
    } catch (PDOException $exception) {
        $deadlock = $exception;
    }

    example_expect_same(1213, $deadlock instanceof PDOException ? example_error_code($deadlock) : null, 'propagated driver code');
    example_expect_same(1, $calls, 'callback executions (no replay inside a caller-owned transaction)');
    example_expect_same([], example_added_labels($pdo, $table), 'rows visible to the caller (whole transaction rolled back)');
    example_expect_same(false, $pdo->inTransaction(), 'inTransaction() after the next successful statement');
    example_expect_same([], example_added_labels($observer, $table), 'committed rows');
    example_expect_same('ACQUIRED', $partner->finish(), 'partner got row 1 after the victim was rolled back');

    example_say('The caller-owned transaction was the deadlock victim; the runner did not replay it.');
});

example_say('OK: 02_caller_owned_no_replay');
