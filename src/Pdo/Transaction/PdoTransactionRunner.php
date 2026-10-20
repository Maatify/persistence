<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Transaction;

use PDO;
use Throwable;

/**
 * Runs a callback with transaction ownership only when the PDO connection is
 * not already inside a transaction.
 *
 * @deprecated Use PdoDeadlockRetryTransactionRunner, which has the same
 * ownership and participation behavior (and no deadlock replay with its default
 * configuration). This class stays public, loadable and behavior-compatible in
 * the 1.x line; it is not removed before a future Major release.
 */
final readonly class PdoTransactionRunner implements TransactionRunnerInterface
{
    public function __construct(private PDO $pdo)
    {
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

        $this->pdo->beginTransaction();

        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $throwable) {
            $this->rollBackIfActive();

            throw $throwable;
        }
    }

    private function rollBackIfActive(): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }

        try {
            $this->pdo->rollBack();
        } catch (Throwable) {
        }
    }
}
