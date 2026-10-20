<?php

declare(strict_types=1);

/**
 * Example - moveWithinScope() inside a caller-owned REPEATABLE READ transaction
 * whose snapshot is older than another connection's commit.
 *
 * Two connections. Connection A opens a transaction and takes its snapshot with a
 * normal read, when the scope holds the rows a, b, c at positions 1-3. Connection
 * B then commits d and e at positions 4 and 5. Connection A moves `a` to position
 * 5 with moveWithinScope().
 *
 * moveWithinScope() clamps the requested position to the maximum of the rows
 * returned by its own scope-locking `SELECT ... FOR UPDATE`, which reads the
 * latest committed state, not the transaction's older snapshot. So the target
 * position 5 is reached:
 *
 *   before: a=1 b=2 c=3 d=4 e=5   ->   after: b=1 c=2 d=3 e=4 a=5
 *
 * Part 2 shows that the caller keeps ownership: the manager neither commits nor
 * rolls back the caller's transaction, so a caller rollback restores the rows.
 * The manager also does not undo its own partial work inside a caller-owned
 * transaction (no savepoint): after a failure the caller must roll back.
 * This script uses a non-unique ordering index; moveWithinScope() is not
 * compatible with a UNIQUE (scope, order) index.
 * Part 3 shows the same call without a caller transaction (runner-owned).
 *
 * This is the movement path. It does not call getNextPositionForUpdate(), which
 * is for allocating the position of a new row (see examples/next-position).
 *
 * Run: php examples/move-within-scope/01_move_after_older_snapshot.php
 */

require __DIR__ . '/_support.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

example_with_schema(static function (PDO $pdo, array $tables): void {
    $other = example_connect();
    $config = new ScopedOrderingConfig(table: $tables['items'], scopeColumn: 'playlist_id', orderColumn: 'position');
    $ordering = new ScopedOrderingManager();
    $items = $tables['items'];

    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

    $commitFromOther = static function (int $playlistId) use ($other, $items): void {
        $insert = $other->prepare(
            'INSERT INTO ' . $items . ' (playlist_id, position, title) VALUES (:playlist_id, :position, :title)',
        );
        $insert->execute(['playlist_id' => $playlistId, 'position' => 4, 'title' => 'd']);
        $insert->execute(['playlist_id' => $playlistId, 'position' => 5, 'title' => 'e']);
    };

    // 1. Older snapshot, move to the latest position, caller commits.
    example_seed($pdo, $items, 1, ['a', 'b', 'c']);
    $pdo->beginTransaction();

    try {
        // The first non-locking read creates the REPEATABLE READ snapshot: rows a, b, c.
        example_scalar($pdo, 'SELECT COUNT(*) FROM ' . $items);

        // Another connection commits d and e after that snapshot.
        $commitFromOther(1);

        $moved = $ordering->moveWithinScope($pdo, $config, 1, example_id_of($other, $items, 1, 'a'), 5);

        example_expect_same(true, $moved, 'moveWithinScope() returned true');
        example_expect($pdo->inTransaction(), 'the caller-owned transaction is still active');
        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $throwable;
    }

    example_expect_same(['b', 'c', 'd', 'e', 'a'], example_titles_by_position($other, $items, 1), 'a reached position 5');
    example_expect_same([1, 2, 3, 4, 5], example_positions($other, $items, 1), 'positions stay unique and contiguous');
    example_say('1. Older snapshot (a,b,c), d and e committed meanwhile: a moved to 5 -> [b, c, d, e, a].');

    // 2. Same scenario, but the caller rolls back: the manager did not commit.
    example_seed($pdo, $items, 2, ['a', 'b', 'c']);
    $pdo->beginTransaction();

    try {
        example_scalar($pdo, 'SELECT COUNT(*) FROM ' . $items);
        $commitFromOther(2);

        example_expect_same(true, $ordering->moveWithinScope($pdo, $config, 2, example_id_of($other, $items, 2, 'a'), 5), 'move succeeded');
        example_expect($pdo->inTransaction(), 'the caller-owned transaction is still active');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    example_expect_same(['a', 'b', 'c', 'd', 'e'], example_titles_by_position($other, $items, 2), 'caller rollback restored the order');
    example_say('2. Caller rollback: [a, b, c, d, e] again; the manager never ended the caller transaction.');

    // 3. No caller transaction: the manager owns (begins and commits) its own.
    example_seed($pdo, $items, 3, ['a', 'b', 'c']);
    example_expect(! $pdo->inTransaction(), 'no transaction before the call');
    example_expect_same(true, $ordering->moveWithinScope($pdo, $config, 3, example_id_of($other, $items, 3, 'a'), 3), 'owned move succeeded');
    example_expect(! $pdo->inTransaction(), 'the owned transaction was committed and closed');
    example_expect_same(['b', 'c', 'a'], example_titles_by_position($other, $items, 3), 'owned move result');
    example_say('3. No caller transaction: owned transaction committed -> [b, c, a].');

    example_say('OK: 01_move_after_older_snapshot');
});
