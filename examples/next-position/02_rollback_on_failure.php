<?php

declare(strict_types=1);

/**
 * Example 02 - what happens to an allocated position when the work fails.
 *
 * The position is only a number until the INSERT commits. If anything fails
 * after allocation, the transaction rolls back, the lock is released, the
 * original Throwable propagates unchanged, and the position is simply free
 * again.
 *
 *   A. A host failure after allocation and INSERT.
 *   B. A database failure on the INSERT itself.
 *
 * Run: php examples/next-position/02_rollback_on_failure.php
 */

require __DIR__ . '/_support.php';

use Maatify\Persistence\Exception\PersistenceException;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Pdo\Transaction\PdoTransactionRunner;

example_with_schema(static function (PDO $pdo, array $tables): void {
    $playlistId = 3;
    example_seed($pdo, $tables['items'], $playlistId, ['One', 'Two', 'Three']);

    $config = new ScopedOrderingConfig(table: $tables['items'], scopeColumn: 'playlist_id', orderColumn: 'position');
    $ordering = new ScopedOrderingManager();
    $transactions = new PdoTransactionRunner($pdo);
    $insert = $pdo->prepare(
        'INSERT INTO ' . $config->quotedTable() . ' (playlist_id, position, title)
         VALUES (:playlist_id, :position, :title)',
    );

    // A. Host failure after a successful INSERT.
    $hostFailure = new RuntimeException('host rejected the request after the insert');
    $caught = null;

    try {
        $transactions->run(
            static function () use ($pdo, $ordering, $config, $insert, $playlistId, $hostFailure): never {
                $position = $ordering->getNextPositionForUpdate($pdo, $config, $playlistId);
                $insert->execute(['playlist_id' => $playlistId, 'position' => $position, 'title' => 'Four']);

                throw $hostFailure;
            },
        );
    } catch (Throwable $throwable) {
        $caught = $throwable;
    }

    example_expect_same($hostFailure, $caught, 'the original Throwable is rethrown, not wrapped');
    example_expect(! $pdo->inTransaction(), 'the runner rolled back and closed its transaction');
    example_expect_same([1, 2, 3], example_positions($pdo, $tables['items'], $playlistId), 'rolled-back row is gone');

    // B. Database failure on the INSERT (title is NOT NULL). PDOException is not
    //    a PersistenceException: the package does not wrap external errors.
    $caught = null;

    try {
        $transactions->run(
            static function () use ($pdo, $ordering, $config, $insert, $playlistId): void {
                $position = $ordering->getNextPositionForUpdate($pdo, $config, $playlistId);
                $insert->execute(['playlist_id' => $playlistId, 'position' => $position, 'title' => null]);
            },
        );
    } catch (Throwable $throwable) {
        $caught = $throwable;
    }

    example_expect($caught instanceof PDOException, 'the INSERT failure propagates as the original PDOException');
    example_expect(! ($caught instanceof PersistenceException), 'PDOException is not wrapped in a package exception');
    example_expect(! $pdo->inTransaction(), 'no transaction is left open after the failure');

    // The rolled-back position is free again.
    example_expect_same(4, $ordering->getNextPosition($pdo, $config, $playlistId), 'position 4 is allocatable again');

    example_say('Both failures rolled back; position 4 is still free.');
    example_say('OK: 02_rollback_on_failure');
});
