<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Transaction;

use PDO;

// RED skeleton (temporary): no deadlock retry yet.
final readonly class PdoDeadlockRetrySavepointTransactionRunner implements SavepointTransactionRunnerInterface
{
    public function __construct(private PDO $pdo, int $maxDeadlockRetries = 0)
    {
    }

    public function run(callable $callback): mixed
    {
        return (new PdoSavepointTransactionRunner($this->pdo))->run($callback);
    }
}
