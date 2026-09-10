<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Transaction;

use PDO;
use Throwable;

/**
 * Runs a callback with transaction ownership only when the PDO connection is
 * not already inside a transaction.
 */
final readonly class PdoTransactionRunner implements TransactionRunnerInterface
{
    /**
     * @template TResult
     *
     * @param callable(): TResult $callback
     * @return TResult
     */
    public function run(PDO $pdo, callable $callback): mixed
    {
        if ($pdo->inTransaction()) {
            return $callback();
        }

        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (Throwable $throwable) {
            $this->rollBackIfActive($pdo);

            throw $throwable;
        }
    }

    private function rollBackIfActive(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) {
            return;
        }

        try {
            $pdo->rollBack();
        } catch (Throwable) {
        }
    }
}
