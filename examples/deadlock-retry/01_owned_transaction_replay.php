<?php

declare(strict_types=1);

/**
 * Example 01 - a real deadlock on a runner-owned transaction is replayed.
 *
 * PdoDeadlockRetryTransactionRunner owns the transaction when none is active.
 * Configured with maxDeadlockRetries: 3, it replays the WHOLE callback from a
 * fresh transaction when MySQL picks that transaction as a deadlock victim
 * (driver code 1213, SQLSTATE 40001): immediately the first time, then after a
 * random 0-10 ms and 0-20 ms wait. The default (0) never replays.
 *
 * What the script proves against real MySQL:
 *   - Attempt 1 is chosen as the victim of a genuine InnoDB deadlock.
 *   - The runner replays the callback (2 attempts) and commits exactly once:
 *     the rolled-back first attempt leaves no row behind.
 *   - The side effect that a rollback cannot undo (here: "sending an e-mail")
 *     is done AFTER run() returns, so it happens once even though the callback
 *     ran twice. Never put such effects inside a retryable callback.
 *
 * The partner process and the observer connection only exist to make the
 * deadlock real and deterministic; they are not part of a normal application.
 *
 * Run: php examples/deadlock-retry/01_owned_transaction_replay.php
 */

require __DIR__ . '/_support.php';

use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetryTransactionRunner;

example_with_table(static function (PDO $pdo, string $table): void {
    $observer = example_connect();
    $partner = ExampleDeadlockPartner::start($table); // holds row 2

    $transactions = new PdoDeadlockRetryTransactionRunner($pdo, maxDeadlockRetries: 3);
    $attempts = 0;

    $orderId = $transactions->run(function () use ($pdo, $table, $observer, $partner, &$attempts): int {
        $attempts++;

        // The unit of work: everything happens on $pdo, inside the runner-owned transaction.
        example_lock_row($pdo, $table, 1);

        if ($attempts === 1) {
            // Only to make attempt 1 a real deadlock victim: the partner now waits for row 1.
            $partner->queueBehindRowOne($observer);
        }

        example_lock_row($pdo, $table, 2); // attempt 1: PDOException 1213 (deadlock victim)

        $insert = $pdo->prepare('INSERT INTO ' . $table . ' (label) VALUES (:label)');
        $insert->execute(['label' => 'order-created']);

        return (int) $pdo->lastInsertId();
    });

    // After COMMIT: effects that a rollback cannot undo run once, whatever the number of attempts.
    $outbox = ['order confirmation for #' . $orderId];

    example_expect_same(2, $attempts, 'attempts (initial attempt + one replay)');
    example_expect_same(['order-created'], example_added_labels($observer, $table), 'committed rows');
    example_expect_same(true, $orderId > 2, 'returned id of the committed row');
    example_expect_same(1, count($outbox), 'external side effects');
    example_expect_same('ACQUIRED', $partner->finish(), 'partner got row 1 after the victim was rolled back');
    example_expect_same(false, $pdo->inTransaction(), 'no transaction left open');

    example_say('Attempt 1 was a real deadlock victim; the runner replayed the transaction and committed once.');
});

example_say('OK: 01_owned_transaction_replay');
