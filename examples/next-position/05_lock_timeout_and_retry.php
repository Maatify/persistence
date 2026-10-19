<?php

declare(strict_types=1);

/**
 * Example 05 - lock waits, lock timeouts, and the caller's retry responsibility.
 *
 * getNextPositionForUpdate() takes row locks, so it can wait for another
 * transaction. If the wait times out (1205) or InnoDB picks the transaction as a
 * deadlock victim (1213), the PDOException propagates unchanged: the package does
 * not retry, wrap, or hide it. The caller rolls back and retries the whole unit
 * of work in a NEW transaction.
 *
 *   1. One attempt fails with 1205 while another transaction holds the tail.
 *   2. A caller-side retry loop recovers once the holder has committed.
 *
 * A deadlock (1213) cannot be provoked deterministically from one process; it is
 * covered with separate processes by the package integration tests and handled
 * here by the same retry classification.
 *
 * Run: php examples/next-position/05_lock_timeout_and_retry.php
 */

require __DIR__ . '/_support.php';

use Maatify\Persistence\Exception\PersistenceException;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

/**
 * Host-owned retry policy: run the unit of work in a fresh transaction per
 * attempt; retry only on lock wait timeout (1205) or deadlock (1213).
 *
 * @template T
 * @param callable(): T $unitOfWork
 * @param callable(int): void $beforeRetry
 * @return T
 */
function run_with_lock_retry(PDO $pdo, int $maxAttempts, callable $unitOfWork, callable $beforeRetry): mixed
{
    for ($attempt = 1; ; $attempt++) {
        $pdo->beginTransaction();

        try {
            $result = $unitOfWork();
            $pdo->commit();

            return $result;
        } catch (Throwable $throwable) {
            // A lock wait timeout (1205) rolls back only the failed statement by
            // default, so the transaction must always be ended explicitly.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $retryable = $throwable instanceof PDOException
                && in_array(example_error_code($throwable), [1205, 1213], true);

            if (! $retryable || $attempt >= $maxAttempts) {
                throw $throwable;
            }

            $beforeRetry($attempt);
        }
    }
}

example_with_schema(static function (PDO $pdo, array $tables): void {
    $holder = example_connect();
    $playlistId = 20;
    example_seed($pdo, $tables['items'], $playlistId, ['a', 'b', 'c']);

    $config = new ScopedOrderingConfig(table: $tables['items'], scopeColumn: 'playlist_id', orderColumn: 'position');
    $ordering = new ScopedOrderingManager();
    $insert = static function (PDO $connection, int $position, string $title) use ($config, $playlistId): void {
        $connection->prepare(
            'INSERT INTO ' . $config->quotedTable() . ' (playlist_id, position, title)
             VALUES (:playlist_id, :position, :title)',
        )->execute(['playlist_id' => $playlistId, 'position' => $position, 'title' => $title]);
    };

    // The holder allocates and inserts position 4 but has not committed yet.
    $holder->beginTransaction();
    $insert($holder, $ordering->getNextPositionForUpdate($holder, $config, $playlistId), 'holder');

    // Keep the demonstration fast: waiters give up after one second.
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

    // 1. A single attempt fails with the original PDOException (1205).
    $pdo->beginTransaction();

    try {
        $ordering->getNextPositionForUpdate($pdo, $config, $playlistId);
        throw new RuntimeException('Expected a lock wait timeout.');
    } catch (PDOException $exception) {
        example_expect_same(1205, example_error_code($exception), 'lock wait timeout error code');
        example_expect(! ($exception instanceof PersistenceException), 'the timeout is not wrapped in a package exception');
    } finally {
        $pdo->rollBack();
    }

    example_say('1. A blocked attempt failed with the original PDOException 1205 and was rolled back.');

    // 2. Retry in a new transaction; the holder commits between attempts.
    $position = run_with_lock_retry(
        $pdo,
        3,
        static function () use ($pdo, $ordering, $config, $playlistId, $insert): int {
            $position = $ordering->getNextPositionForUpdate($pdo, $config, $playlistId);
            $insert($pdo, $position, 'retried');

            return $position;
        },
        static function (int $failedAttempt) use ($holder): void {
            example_say('   attempt ' . $failedAttempt . ' hit a lock failure; the other transaction commits, then retry.');
            $holder->commit();
        },
    );

    example_expect_same(5, $position, 'the retry followed the tail committed by the holder');
    example_expect_same([1, 2, 3, 4, 5], example_positions($pdo, $tables['items'], $playlistId), 'committed positions after the retry');
    example_say('2. The retry in a new transaction allocated position 5.');

    example_say('OK: 05_lock_timeout_and_retry');
});
