<?php

declare(strict_types=1);

/**
 * Example 05 - lock waits, lock timeouts, and the caller's retry responsibility.
 *
 * getNextPositionForUpdate() takes row locks, so it can wait for another
 * transaction. If the wait times out (1205) or InnoDB picks the transaction as a
 * deadlock victim (1213), the PDOException propagates unchanged: the package does
 * not retry, wrap, or hide it. Whether and how to retry is the host's decision.
 * This script shows ONE caller-owned policy and proves its boundaries; it is not
 * package API and not a retry feature of the package.
 *
 * When is re-running the unit of work safe?
 *   - It must be idempotent from the database's point of view: everything it does
 *     is inside the transaction, so the rollback undoes it before the next attempt.
 *   - It must not perform effects a ROLLBACK cannot undo (send a message, call an
 *     external API, enqueue a job, write a file). Do those AFTER the retry helper
 *     returns, i.e. after COMMIT. Section 6 shows exactly one such effect even
 *     though two attempts ran.
 *   - The helper owns the transaction. It refuses to run inside a transaction
 *     someone else owns, because a retry would have to roll that transaction back.
 *
 * Sections:
 *   1. A single blocked attempt fails with the original PDOException 1205.
 *   2. Attempts are bounded: exhaustion rethrows the original 1205, locks released.
 *   3. Errors other than 1205/1213 are never retried.
 *   4. The helper refuses to run inside a caller-owned transaction.
 *   5. A deadlock (1213) is classified as retryable (simulated; see below).
 *   6. A real retry succeeds after the blocker commits; the external effect runs once.
 *
 * A real deadlock cannot be provoked deterministically from one PHP process; it is
 * covered with separate processes by the package integration tests. Section 5
 * therefore simulates the driver error only to prove the classification branch.
 *
 * Run: php examples/next-position/05_lock_timeout_and_retry.php
 */

require __DIR__ . '/_support.php';

use Maatify\Persistence\Exception\PersistenceException;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

/**
 * A retry has to roll the failed attempt back, which is only legitimate for a
 * transaction this code started.
 */
function refuse_active_transaction(PDO $pdo): void
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Retrying requires owning the transaction; one is already active.');
    }
}

/**
 * Host-owned retry policy: run the unit of work in a fresh transaction per
 * attempt; retry only on lock wait timeout (1205) or deadlock (1213), at most
 * $maxAttempts times; rethrow the original Throwable otherwise.
 *
 * The unit of work must be safe to run again (see the file header).
 *
 * @template T
 * @param callable(): T $unitOfWork
 * @param callable(int): void $beforeRetry
 * @return T
 */
function run_with_lock_retry(PDO $pdo, int $maxAttempts, callable $unitOfWork, callable $beforeRetry): mixed
{
    refuse_active_transaction($pdo);

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
    $probe = example_connect();
    $playlistId = 20;
    example_seed($pdo, $tables['items'], $playlistId, ['a', 'b', 'c']);
    $pdo->prepare('INSERT INTO ' . $tables['playlists'] . ' (id, name) VALUES (:id, :name)')
        ->execute(['id' => $playlistId, 'name' => 'Contended']);

    $config = new ScopedOrderingConfig(table: $tables['items'], scopeColumn: 'playlist_id', orderColumn: 'position');
    $ordering = new ScopedOrderingManager();
    $insert = static function (PDO $connection, int $position, string $title) use ($config, $playlistId): void {
        $connection->prepare(
            'INSERT INTO ' . $config->quotedTable() . ' (playlist_id, position, title)
             VALUES (:playlist_id, :position, :title)',
        )->execute(['playlist_id' => $playlistId, 'position' => $position, 'title' => $title]);
    };
    $lockParentSql = 'SELECT id FROM ' . $tables['playlists'] . ' WHERE id = :id FOR UPDATE';

    // The unit of work: database effects only. It first takes a free parent-row
    // lock (so a leaked lock after a failed attempt would be observable), then
    // allocates and inserts.
    $attempts = 0;
    $allocateAndInsert = static function () use ($pdo, $ordering, $config, $playlistId, $insert, $lockParentSql, &$attempts): int {
        $attempts++;
        $pdo->prepare($lockParentSql)->execute(['id' => $playlistId]);
        $position = $ordering->getNextPositionForUpdate($pdo, $config, $playlistId);
        $insert($pdo, $position, 'retried');

        return $position;
    };
    $noWait = static function (int $failedAttempt): void {
    };

    // The holder allocates and inserts position 4 but has not committed yet.
    $holder->beginTransaction();
    $insert($holder, $ordering->getNextPositionForUpdate($holder, $config, $playlistId), 'holder');

    // Keep the demonstration fast: waiters give up after one second.
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $probe->exec('SET SESSION innodb_lock_wait_timeout = 1');

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

    // 2. Exhaustion: the blocker never commits, so both attempts fail.
    $attempts = 0;
    $exhausted = null;

    try {
        run_with_lock_retry($pdo, 2, $allocateAndInsert, $noWait);
    } catch (Throwable $throwable) {
        $exhausted = $throwable;
    }

    example_expect($exhausted instanceof PDOException, 'exhaustion rethrows the original PDOException');
    example_expect_same(1205, $exhausted instanceof PDOException ? example_error_code($exhausted) : null, 'exhausted error code');
    example_expect_same(2, $attempts, 'the attempt bound was honored');
    example_expect(! $pdo->inTransaction(), 'no transaction is left open after exhaustion');

    // The failed attempts took the parent-row lock before blocking. If a
    // rollback had not released it, this probe would time out with 1205.
    $probe->beginTransaction();
    $probe->prepare($lockParentSql)->execute(['id' => $playlistId]);
    $probe->rollBack();
    example_say('2. Retries are bounded: 2 attempts, original 1205 rethrown, no lock leaked.');

    // 3. Errors that are not 1205/1213 are never retried.
    $attempts = 0;
    $constraintFailure = null;

    try {
        run_with_lock_retry(
            $pdo,
            3,
            static function () use ($pdo, $tables, $playlistId, &$attempts): void {
                $attempts++;
                $pdo->prepare(
                    'INSERT INTO ' . $tables['items'] . ' (playlist_id, position, title) VALUES (:p, 99, NULL)',
                )->execute(['p' => $playlistId]);
            },
            $noWait,
        );
    } catch (Throwable $throwable) {
        $constraintFailure = $throwable;
    }

    example_expect($constraintFailure instanceof PDOException, 'a non-lock PDOException propagates');
    example_expect_same(
        1048,
        $constraintFailure instanceof PDOException ? example_error_code($constraintFailure) : null,
        'NOT NULL violation error code',
    );
    example_expect_same(1, $attempts, 'a non-lock PDOException is not retried');
    example_expect(! $pdo->inTransaction(), 'no transaction is left open after a non-retryable PDOException');

    $hostFailure = new RuntimeException('host failure');
    $attempts = 0;
    $caught = null;

    try {
        run_with_lock_retry(
            $pdo,
            3,
            static function () use ($hostFailure, &$attempts): void {
                $attempts++;

                throw $hostFailure;
            },
            $noWait,
        );
    } catch (Throwable $throwable) {
        $caught = $throwable;
    }

    example_expect_same($hostFailure, $caught, 'a non-PDO Throwable propagates unchanged');
    example_expect_same(1, $attempts, 'a non-PDO Throwable is not retried');
    example_expect(! $pdo->inTransaction(), 'no transaction is left open after a non-PDO failure');
    example_say('3. Constraint errors and host failures are not retried; the original Throwable propagates.');

    // 4. The helper refuses to run inside a transaction it does not own.
    $attempts = 0;
    $pdo->beginTransaction();

    try {
        run_with_lock_retry($pdo, 3, $allocateAndInsert, $noWait);
        throw new RuntimeException('Expected the helper to refuse an active transaction.');
    } catch (LogicException) {
        example_expect_same(0, $attempts, 'the unit of work did not run');
        example_expect($pdo->inTransaction(), 'the caller-owned transaction was not touched');
    } finally {
        $pdo->rollBack();
    }

    example_say('4. The helper refused to retry inside a caller-owned transaction.');

    // 5. Deadlock classification (simulated driver error, see the file header).
    $deadlock = new PDOException('Deadlock found when trying to get lock; try restarting transaction');
    $deadlock->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
    $attempts = 0;

    $result = run_with_lock_retry(
        $pdo,
        2,
        static function () use ($deadlock, &$attempts): string {
            $attempts++;

            if ($attempts === 1) {
                throw $deadlock;
            }

            return 'recovered';
        },
        $noWait,
    );

    example_expect_same('recovered', $result, 'a deadlock-classified failure is retried');
    example_expect_same(2, $attempts, 'the deadlock was retried exactly once');
    example_say('5. A deadlock (1213, simulated) is classified as retryable.');

    // 6. A real retry: the holder commits between attempts. The external effect
    //    (a notification) happens once, after COMMIT, never inside the retried unit.
    $attempts = 0;
    $notifications = [];

    $position = run_with_lock_retry(
        $pdo,
        3,
        $allocateAndInsert,
        static function (int $failedAttempt) use ($holder): void {
            example_say('   attempt ' . $failedAttempt . ' hit a lock failure; the other transaction commits, then retry.');
            $holder->commit();
        },
    );
    $notifications[] = 'position ' . $position . ' committed';

    example_expect_same(5, $position, 'the retry followed the tail committed by the holder');
    example_expect_same(2, $attempts, 'the unit of work ran twice');
    example_expect_same(['position 5 committed'], $notifications, 'the external effect ran exactly once, after commit');
    example_expect_same([1, 2, 3, 4, 5], example_positions($pdo, $tables['items'], $playlistId), 'committed positions after the retry');
    example_say('6. The retry in a new transaction allocated position 5; the external effect ran once.');

    example_say('OK: 05_lock_timeout_and_retry');
});
