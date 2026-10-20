<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Transaction;

use Maatify\Persistence\Exception\InvalidTransactionConfigurationException;
use Maatify\Persistence\Exception\TransactionExecutionException;
use Maatify\Persistence\Pdo\Transaction\Internal\OwnedTransactionExecutor;
use PDO;
use Throwable;

/**
 * Runs a callback with an operation-local savepoint inside an existing PDO
 * transaction, and owns the transaction (with optional deadlock replay) when
 * no transaction is active.
 *
 * - No active transaction: the same owned-transaction behavior as
 *   PdoDeadlockRetryTransactionRunner, including the optional replay of the
 *   whole owned transaction after a MySQL deadlock (SQLSTATE 40001, driver code
 *   1213), at most $maxDeadlockRetries times, after the server is proven to have
 *   ended it. No savepoint SQL is issued on this path.
 * - Active caller-owned transaction: creates an operation-local savepoint
 *   (maatify_persistence_sp_<32 lowercase hex>), releases it on success and
 *   rolls back to it (best effort) on failure. It never commits, fully rolls
 *   back or retries the caller-owned transaction or the savepoint, even on a
 *   deadlock; the callback Throwable (or RELEASE failure) always wins over
 *   cleanup failures.
 *
 * Replay re-executes the WHOLE callback of an owned transaction. Its effects
 * outside this PDO connection are not rolled back and would be repeated; keep
 * them outside the callback or make them idempotent. The callback must not
 * begin, commit or roll back the runner-owned transaction itself.
 */
final readonly class PdoDeadlockRetrySavepointTransactionRunner implements SavepointTransactionRunnerInterface
{
    private const SAVEPOINT_PREFIX = 'maatify_persistence_sp_';

    private OwnedTransactionExecutor $ownedTransaction;

    /**
     * @param int $maxDeadlockRetries 0 (default: no replay) to 3 replays of an owned transaction after a deadlock
     *
     * @throws InvalidTransactionConfigurationException when $maxDeadlockRetries is outside 0..3
     */
    public function __construct(private PDO $pdo, int $maxDeadlockRetries = 0)
    {
        $this->ownedTransaction = new OwnedTransactionExecutor($pdo, $maxDeadlockRetries);
    }

    /**
     * @template TResult
     *
     * @param callable(): TResult $callback
     * @return TResult
     */
    public function run(callable $callback): mixed
    {
        if (!$this->pdo->inTransaction()) {
            return $this->ownedTransaction->run($callback);
        }

        $savepointName = $this->generateSavepointName();
        $this->executeControlStatement('SAVEPOINT ' . $savepointName);

        try {
            $result = $callback();
        } catch (Throwable $callbackThrowable) {
            $this->cleanupAfterCallbackFailure($savepointName);

            throw $callbackThrowable;
        }

        try {
            $this->executeControlStatement('RELEASE SAVEPOINT ' . $savepointName);
        } catch (Throwable $releaseThrowable) {
            $this->cleanupAfterReleaseFailure($savepointName);

            throw $releaseThrowable;
        }

        return $result;
    }

    private function generateSavepointName(): string
    {
        return self::SAVEPOINT_PREFIX . bin2hex(random_bytes(16));
    }

    private function executeControlStatement(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            throw new TransactionExecutionException('PDO transaction control statement failed.');
        }
    }

    private function cleanupAfterCallbackFailure(string $savepointName): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }

        if (!$this->tryControlStatement('ROLLBACK TO SAVEPOINT ' . $savepointName)) {
            return;
        }

        $this->tryControlStatement('RELEASE SAVEPOINT ' . $savepointName);
    }

    private function cleanupAfterReleaseFailure(string $savepointName): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }

        if (!$this->tryControlStatement('ROLLBACK TO SAVEPOINT ' . $savepointName)) {
            return;
        }

        $this->tryControlStatement('RELEASE SAVEPOINT ' . $savepointName);
    }

    private function tryControlStatement(string $sql): bool
    {
        try {
            $this->executeControlStatement($sql);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
