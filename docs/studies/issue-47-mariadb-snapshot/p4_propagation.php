<?php

declare(strict_types=1);

/**
 * P4 - Error propagation through the shipped runners. When the engine raises 1020 inside a nested package call,
 * which exception reaches the caller, and what is the state of the caller's outer transaction afterwards?
 */

require __DIR__ . '/lib.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Pdo\Transaction\PdoSavepointTransactionRunner;

$pdo = p47_pdo();
$other = p47_pdo();
echo "== P4 " . p47_engine($pdo) . "\n";
$T = P47_T;
$manager = new ScopedOrderingManager();

foreach (['direct' => false, 'inside savepoint runner' => true] as $label => $viaSavepoint) {
    p47_seed($pdo);
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO $T (scope_id, display_order) VALUES (7, 1)");           // caller's earlier write
    $pdo->query("SELECT COUNT(*) FROM $T")->fetchAll();
    $other->exec("INSERT INTO $T (scope_id, display_order) VALUES (1, 4)");

    $thrown = 'none';
    try {
        $call = static fn () => $manager->getNextPositionForUpdate($pdo, p47_config(), 1);
        $viaSavepoint ? (new PdoSavepointTransactionRunner($pdo))->run($call) : $call();
    } catch (Throwable $e) {
        $thrown = get_class($e) . ' ' . (($e instanceof PDOException) ? ($e->errorInfo[1] ?? '?') : $e->getMessage());
    }

    $pdoSays = var_export($pdo->inTransaction(), true);
    $serverSays = p47_try(static fn () => $pdo->query('SELECT @@in_transaction')->fetchColumn());
    $earlierWrite = $other->query("SELECT COUNT(*) FROM $T WHERE scope_id = 7")->fetchColumn();
    // The caller catches the error, "continues", writes, then rolls back the transaction it believes it still owns.
    $pdo->exec("INSERT INTO $T (scope_id, display_order) VALUES (8, 1)");
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $afterRollback = $other->query("SELECT COUNT(*) FROM $T WHERE scope_id IN (7,8)")->fetchColumn();
    printf("  %-24s thrown=%s | PDO::inTransaction=%s @@in_transaction=%s | rows(scope 7 = earlier write) visible to others before caller rollBack=%s | after caller rollBack rows(7,8) surviving=%s\n",
        $label, $thrown, $pdoSays, $serverSays, $earlierWrite, $afterRollback);
}

// Package-OWNED transaction hitting 1020 (callback does a plain read first, then the locking API).
p47_seed($pdo);
$thrown = 'none';
try {
    (new Maatify\Persistence\Pdo\Transaction\PdoTransactionRunner($pdo))->run(static function () use ($pdo, $other, $manager, $T): void {
        $pdo->query("SELECT COUNT(*) FROM $T")->fetchAll();
        $other->exec("INSERT INTO $T (scope_id, display_order) VALUES (1, 4)");
        $manager->getNextPositionForUpdate($pdo, p47_config(), 1);
    });
} catch (Throwable $e) {
    $thrown = get_class($e) . ' ' . (($e instanceof PDOException) ? ($e->errorInfo[1] ?? '?') : $e->getMessage());
}
printf("  %-24s thrown=%s | PDO::inTransaction after=%s\n", 'runner-owned txn', $thrown, var_export($pdo->inTransaction(), true));
// Deadlock-retry runner (maxDeadlockRetries=3) owning the transaction: 1020 must NOT be replayed (only 1213 is).
p47_seed($pdo);
$attempts = 0;
$thrown = 'none';
try {
    (new Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetryTransactionRunner($pdo, 3))->run(static function () use ($pdo, $other, $manager, $T, &$attempts): void {
        ++$attempts;
        $pdo->query("SELECT COUNT(*) FROM $T")->fetchAll();
        $other->exec("INSERT INTO $T (scope_id, display_order) VALUES (1, " . (3 + $attempts) . ')');
        $manager->getNextPositionForUpdate($pdo, p47_config(), 1);
    });
} catch (Throwable $e) {
    $thrown = get_class($e) . ' ' . (($e instanceof PDOException) ? ($e->errorInfo[1] ?? '?') : $e->getMessage());
}
printf("  %-24s thrown=%s | callback attempts=%d (1 = not replayed)\n", 'deadlock-retry runner(3)', $thrown, $attempts);
$pdo->exec("DROP TABLE IF EXISTS $T");
