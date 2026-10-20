<?php

declare(strict_types=1);

/**
 * Example 01 - allocate a position and INSERT, end to end.
 *
 * Host input -> ScopedOrderingConfig -> transaction -> getNextPositionForUpdate()
 * -> real INSERT on the same connection -> COMMIT -> observable rows.
 *
 * Shows both ways to own the transaction:
 *   A. PdoDeadlockRetryTransactionRunner owns BEGIN / COMMIT / ROLLBACK.
 *   B. The caller owns them with plain PDO calls.
 *
 * Run: php examples/next-position/01_allocate_and_insert.php
 */

require __DIR__ . '/_support.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetryTransactionRunner;

example_with_schema(static function (PDO $pdo, array $tables): void {
    $playlistId = 7;
    example_seed($pdo, $tables['items'], $playlistId, ['Intro', 'Verse', 'Chorus']);

    // 1. Describe the table. Identifiers are trusted constants, never user input.
    $config = new ScopedOrderingConfig(
        table: $tables['items'],
        scopeColumn: 'playlist_id',
        idColumn: 'id',
        orderColumn: 'position',
        deletedAtColumn: 'deleted_at',
    );
    $ordering = new ScopedOrderingManager();

    $insert = $pdo->prepare(
        'INSERT INTO ' . $config->quotedTable() . ' (playlist_id, position, title)
         VALUES (:playlist_id, :position, :title)',
    );

    // A. The runner begins the transaction, commits on success and rolls back
    //    (rethrowing the original Throwable) on failure.
    $transactions = new PdoDeadlockRetryTransactionRunner($pdo);
    $bridgeId = $transactions->run(
        static function () use ($pdo, $ordering, $config, $insert, $playlistId): int {
            $position = $ordering->getNextPositionForUpdate($pdo, $config, $playlistId);
            $insert->execute(['playlist_id' => $playlistId, 'position' => $position, 'title' => 'Bridge']);

            return $position;
        },
    );

    example_expect_same(4, $bridgeId, 'position allocated by the runner-owned transaction');
    example_expect(! $pdo->inTransaction(), 'the runner closed the transaction it started');

    // B. The caller owns the transaction explicitly.
    $pdo->beginTransaction();

    try {
        $position = $ordering->getNextPositionForUpdate($pdo, $config, $playlistId);
        $insert->execute(['playlist_id' => $playlistId, 'position' => $position, 'title' => 'Outro']);

        // The method never ends the transaction; the lock is still held here.
        example_expect($pdo->inTransaction(), 'getNextPositionForUpdate() left the transaction open');

        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $throwable;
    }

    example_expect_same(5, $position, 'position allocated by the caller-owned transaction');
    example_expect_same([1, 2, 3, 4, 5], example_positions($pdo, $tables['items'], $playlistId), 'committed positions');

    example_say('Allocated positions 4 and 5 and committed both inserts.');
    example_say('OK: 01_allocate_and_insert');
});
