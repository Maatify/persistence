<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Transaction;

use Maatify\Persistence\Exception\InvalidTransactionConfigurationException;
use Maatify\Persistence\Pdo\Transaction\Internal\OwnedTransactionExecutor;
use PDO;

/**
 * Runs a callback with transaction ownership only when the PDO connection is
 * not already inside a transaction, and can replay that owned transaction after
 * a MySQL deadlock.
 *
 * - No active transaction: begins, runs the callback, commits; on failure rolls
 *   back if the transaction is still active and rethrows the original Throwable.
 *   A BEGIN or COMMIT that returns false instead of throwing raises
 *   TransactionExecutionException: the callback never runs without the
 *   transaction, and an uncommitted result is never returned.
 * - Active caller-owned transaction: runs the callback inside it without
 *   beginning, committing, rolling back or retrying anything.
 *
 * With $maxDeadlockRetries > 0, an owned transaction whose callback fails with a
 * PDOException carrying SQLSTATE 40001 and driver code 1213 is replayed from a
 * fresh BEGIN, at most $maxDeadlockRetries times, when the end of the
 * transaction is attributed to that deadlock (the driver still reports the
 * transaction when the deadlock arrives, and a no-op statement then shows it
 * ended). A transaction the callback already ended is not replayed. Delays
 * before retry 1, 2 and 3: none, uniform 0-10 ms, uniform 0-20 ms. After the
 * last attempt the exact final PDOException is rethrown.
 * Nothing else is retried: not 1205 or other errors, not BEGIN or COMMIT
 * failures, not caller-owned transactions.
 *
 * Replay re-executes the WHOLE callback. Its effects outside this PDO connection
 * (e-mails, HTTP calls, files, other connections, in-memory state) are not
 * rolled back and would be repeated; keep them outside the callback or make
 * them idempotent. The callback must not begin, commit or roll back the
 * runner-owned transaction itself, or commit it implicitly: if it ended the
 * transaction and started another one that then deadlocks, the runner cannot
 * tell and would replay. Keeping the runner-owned transaction intact is a
 * mandatory precondition of replay (Owner-accepted limit, ADR 0004).
 */
final readonly class PdoDeadlockRetryTransactionRunner implements TransactionRunnerInterface
{
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
        if ($this->pdo->inTransaction()) {
            return $callback();
        }

        return $this->ownedTransaction->run($callback);
    }
}
