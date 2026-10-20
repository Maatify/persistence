<?php

declare(strict_types=1);

/**
 * Example 03 - getNextPosition() vs getNextPositionForUpdate() under REPEATABLE READ.
 *
 * Two connections. Connection A opens a transaction and takes its snapshot with
 * a normal read; connection B then commits a new row. Same scenario, three ways:
 *
 *   1. getNextPosition() under REPEATABLE READ: stale, and a duplicate position is inserted.
 *   2. getNextPositionForUpdate() under REPEATABLE READ: sees the committed tail, no duplicate.
 *   3. getNextPosition() under READ COMMITTED: no older snapshot, so it is fresh.
 *
 * The first scenario shows the hazard on purpose; the table has a plain
 * (non-unique) index so the duplicate is observable. A UNIQUE index would turn
 * such a mistake into an error, but it is only suitable for tables whose rows are
 * never moved with moveWithinScope(): that method is not compatible with a
 * UNIQUE (scope, order) index (ordinary moves fail with 1062).
 *
 * Run: php examples/next-position/03_stale_snapshot_vs_plain_read.php
 */

require __DIR__ . '/_support.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

/**
 * Connection A snapshots the table, connection B commits a row at position 4,
 * then $allocate runs on connection A inside its transaction and the row is
 * inserted at the allocated position on the same connection.
 *
 * @param array{items: non-empty-string, playlists: non-empty-string} $tables
 * @param callable(): int $allocate
 */
function run_snapshot_scenario(PDO $pdo, PDO $other, array $tables, int $playlistId, callable $allocate): int
{
    example_seed($pdo, $tables['items'], $playlistId, ['a', 'b', 'c']);

    $pdo->beginTransaction();

    try {
        // The first non-locking read creates the REPEATABLE READ snapshot.
        example_scalar($pdo, 'SELECT COUNT(*) FROM ' . $tables['items']);

        // Another connection commits position 4 after that snapshot.
        $other->prepare(
            'INSERT INTO ' . $tables['items'] . ' (playlist_id, position, title) VALUES (:p, 4, :t)',
        )->execute(['p' => $playlistId, 't' => 'committed-by-other']);

        $position = $allocate();
        $pdo->prepare(
            'INSERT INTO ' . $tables['items'] . ' (playlist_id, position, title)
             VALUES (:playlist_id, :position, :title)',
        )->execute(['playlist_id' => $playlistId, 'position' => $position, 'title' => 'mine']);
        $pdo->commit();

        return $position;
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $throwable;
    }
}

example_with_schema(static function (PDO $pdo, array $tables): void {
    $other = example_connect();
    $config = new ScopedOrderingConfig(table: $tables['items'], scopeColumn: 'playlist_id', orderColumn: 'position');
    $ordering = new ScopedOrderingManager();

    // 1. Plain read under REPEATABLE READ (the MySQL default): stale.
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $position = run_snapshot_scenario($pdo, $other, $tables, 1, static function () use ($pdo, $ordering, $config): int {
        $first = $ordering->getNextPosition($pdo, $config, 1);
        // Repeating the plain read in the same transaction cannot recover.
        example_expect_same($first, $ordering->getNextPosition($pdo, $config, 1), 'repeated plain read is equally stale');

        return $first;
    });
    example_expect_same(4, $position, 'plain read returned the stale maximum + 1');
    example_expect_same([1, 2, 3, 4, 4], example_positions($pdo, $tables['items'], 1), 'duplicate position was inserted');
    example_say('1. getNextPosition() under REPEATABLE READ: stale position 4 -> duplicate [1, 2, 3, 4, 4].');

    // 2. Locking read under REPEATABLE READ: follows the committed tail.
    $position = run_snapshot_scenario($pdo, $other, $tables, 2, static fn (): int => $ordering->getNextPositionForUpdate($pdo, $config, 2));
    example_expect_same(5, $position, 'locking read returned the latest committed maximum + 1');
    example_expect_same([1, 2, 3, 4, 5], example_positions($pdo, $tables['items'], 2), 'no duplicate with the locking read');
    example_say('2. getNextPositionForUpdate() under REPEATABLE READ: position 5 -> [1, 2, 3, 4, 5].');

    // 3. Plain read under READ COMMITTED: no older snapshot, so it is fresh.
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $position = run_snapshot_scenario($pdo, $other, $tables, 3, static fn (): int => $ordering->getNextPosition($pdo, $config, 3));
    example_expect_same(5, $position, 'plain read under READ COMMITTED sees the committed row');
    example_expect_same([1, 2, 3, 4, 5], example_positions($pdo, $tables['items'], 3), 'no duplicate under READ COMMITTED');
    example_say('3. getNextPosition() under READ COMMITTED: position 5 -> [1, 2, 3, 4, 5] (still unserialized).');

    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    example_say('OK: 03_stale_snapshot_vs_plain_read');
});
