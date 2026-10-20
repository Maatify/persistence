<?php

declare(strict_types=1);

/**
 * DG-03: a deadlock raised inside PdoDeadlockRetrySavepointTransactionRunner (caller-owned transaction) is
 * caught and the caller carries on, which is exactly what a savepoint user is tempted to do.
 * Reuses the repository's own two-process deadlock scaffolding (examples/deadlock-retry).
 */

require dirname(__DIR__, 4) . '/examples/deadlock-retry/_support.php';

use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner;

example_with_table(static function (PDO $pdo, string $table): void {
    $observer = example_connect();
    $partner = ExampleDeadlockPartner::start($table);
    $savepoints = new PdoDeadlockRetrySavepointTransactionRunner($pdo, 3);

    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO ' . $table . ' (label) VALUES ("caller-before")')->execute();

    try {
        $savepoints->run(function () use ($pdo, $table, $observer, $partner): void {
            example_lock_row($pdo, $table, 1);
            $partner->queueBehindRowOne($observer);
            example_lock_row($pdo, $table, 2);
        });
    } catch (PDOException $exception) {
        echo 'caught driver code ' . example_error_code($exception) . '; inTransaction() right after = ' . var_export($pdo->inTransaction(), true) . "\n";
    }

    $pdo->prepare('INSERT INTO ' . $table . ' (label) VALUES ("caller-after")')->execute();
    echo 'inTransaction() after the next statement = ' . var_export($pdo->inTransaction(), true) . "\n";

    try {
        $pdo->commit();
        echo "commit() succeeded\n";
    } catch (Throwable $throwable) {
        echo 'commit() -> ' . get_class($throwable) . ': ' . $throwable->getMessage() . "\n";
    }

    echo 'committed labels seen by another connection: ' . json_encode(example_added_labels($observer, $table)) . "\n";
    $partner->finish();
});
