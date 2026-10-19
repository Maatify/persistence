<?php

declare(strict_types=1);

/**
 * Example 04 - empty scopes and transaction ownership.
 *
 *   A. getNextPositionForUpdate() without a transaction is rejected, and never starts one.
 *   B. An empty scope has no row to lock, so the host serializes the first insert,
 *      here by locking the parent row first (a second writer waits, then follows).
 *   C. Without such a lock, a UNIQUE index is the safety net: the loser fails with
 *      a duplicate-key error instead of silently duplicating a position.
 *   D. A caller-owned outer transaction: the runner participates and never commits;
 *      the caller's ROLLBACK undoes everything.
 *
 * Run: php examples/next-position/04_empty_scope_and_ownership.php
 */

require __DIR__ . '/_support.php';

use Maatify\Persistence\Exception\InvalidOrderingOperationException;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Pdo\Transaction\PdoTransactionRunner;

example_with_schema(static function (PDO $pdo, array $tables): void {
    $other = example_connect();
    $config = new ScopedOrderingConfig(table: $tables['items'], scopeColumn: 'playlist_id', orderColumn: 'position');
    $ordering = new ScopedOrderingManager();

    $insertSql = 'INSERT INTO ' . $config->quotedTable() . ' (playlist_id, position, title)
                  VALUES (:playlist_id, :position, :title)';
    $lockParentSql = 'SELECT id FROM ' . $tables['playlists'] . ' WHERE id = :id FOR UPDATE';

    // A. A locking read needs a caller-owned transaction.
    try {
        $ordering->getNextPositionForUpdate($pdo, $config, 1);
        throw new RuntimeException('Expected InvalidOrderingOperationException.');
    } catch (InvalidOrderingOperationException) {
        example_expect(! $pdo->inTransaction(), 'the rejected call did not start a transaction');
    }

    example_say('A. Calling getNextPositionForUpdate() outside a transaction is rejected.');

    // B. Empty scope, serialized by the host through the parent row.
    $playlistId = 10;
    $pdo->prepare('INSERT INTO ' . $tables['playlists'] . ' (id, name) VALUES (:id, :name)')
        ->execute(['id' => $playlistId, 'name' => 'Empty at first']);

    $pdo->beginTransaction();
    $pdo->prepare($lockParentSql)->execute(['id' => $playlistId]);
    $first = $ordering->getNextPositionForUpdate($pdo, $config, $playlistId);
    $pdo->prepare($insertSql)->execute(['playlist_id' => $playlistId, 'position' => $first, 'title' => 'first']);

    // A competing writer that takes the same parent lock first has to wait.
    $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $other->beginTransaction();

    try {
        $other->prepare($lockParentSql)->execute(['id' => $playlistId]);
        throw new RuntimeException('Expected the competing writer to wait for the parent lock.');
    } catch (PDOException $exception) {
        example_expect_same(1205, example_error_code($exception), 'the competing writer waited and timed out on the parent lock');
        $other->rollBack();
    }

    $pdo->commit();

    // After the first writer commits, the next one follows the committed tail.
    $other->beginTransaction();
    $other->prepare($lockParentSql)->execute(['id' => $playlistId]);
    $second = $ordering->getNextPositionForUpdate($other, $config, $playlistId);
    $other->prepare($insertSql)->execute(['playlist_id' => $playlistId, 'position' => $second, 'title' => 'second']);
    $other->commit();

    example_expect_same([1, 2], [$first, $second], 'positions allocated for the empty scope');
    example_expect_same([1, 2], example_positions($pdo, $tables['items'], $playlistId), 'committed positions');
    example_say('B. Empty scope serialized by a parent-row lock: positions 1 and 2.');

    // C. No parent lock: the UNIQUE index (this script's table has one) is the safety net.
    //    READ COMMITTED keeps the demonstration deterministic in one process; under
    //    REPEATABLE READ the empty-range lock typically turns this race into a
    //    deadlock or lock timeout instead of a duplicate-key error. Either way it is
    //    an error the caller must handle.
    $emptyScope = 11;
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $other->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    $other->beginTransaction();

    $winner = $ordering->getNextPositionForUpdate($pdo, $config, $emptyScope);
    $loser = $ordering->getNextPositionForUpdate($other, $config, $emptyScope);
    example_expect_same([1, 1], [$winner, $loser], 'both writers read an empty scope and got position 1');

    $pdo->prepare($insertSql)->execute(['playlist_id' => $emptyScope, 'position' => $winner, 'title' => 'winner']);
    $pdo->commit();

    try {
        $other->prepare($insertSql)->execute(['playlist_id' => $emptyScope, 'position' => $loser, 'title' => 'loser']);
        throw new RuntimeException('Expected a duplicate-key error for the losing writer.');
    } catch (PDOException $exception) {
        example_expect_same(1062, example_error_code($exception), 'the UNIQUE index rejected the duplicate position');
        $other->rollBack();
    }

    example_expect_same([1], example_positions($pdo, $tables['items'], $emptyScope), 'only the winner is stored');
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    example_say('C. Unserialized empty scope: UNIQUE index rejected the second writer (1062).');

    // D. Caller-owned outer transaction: the runner participates, the caller decides.
    $owned = 12;
    example_seed($pdo, $tables['items'], $owned, ['x', 'y']);
    $transactions = new PdoTransactionRunner($pdo);

    $pdo->beginTransaction();
    $position = $transactions->run(
        static function () use ($pdo, $ordering, $config, $insertSql, $owned): int {
            $position = $ordering->getNextPositionForUpdate($pdo, $config, $owned);
            $pdo->prepare($insertSql)->execute(['playlist_id' => $owned, 'position' => $position, 'title' => 'z']);

            return $position;
        },
    );
    example_expect_same(3, $position, 'position allocated inside the caller-owned transaction');
    example_expect($pdo->inTransaction(), 'the runner did not commit the caller-owned transaction');

    $pdo->rollBack();
    example_expect_same([1, 2], example_positions($pdo, $tables['items'], $owned), 'the caller rollback undid the insert');
    example_say('D. The runner participated in the outer transaction; the caller rolled everything back.');

    example_say('OK: 04_empty_scope_and_ownership');
}, uniquePosition: true);
