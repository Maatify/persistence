<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Transaction;

use PDO;

// RED skeleton (temporary): no deadlock retry yet.
final readonly class PdoDeadlockRetryTransactionRunner implements TransactionRunnerInterface
{
    public function __construct(private PDO $pdo, int $maxDeadlockRetries = 0)
    {
    }

    public function run(callable $callback): mixed
    {
        return (new PdoTransactionRunner($this->pdo))->run($callback);
    }
}
