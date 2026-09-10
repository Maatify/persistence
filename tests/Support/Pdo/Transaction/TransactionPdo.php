<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\Pdo\Transaction;

use PDO;
use Throwable;

final class TransactionPdo extends PDO
{
    public int $beginTransactionCalls = 0;

    public int $commitCalls = 0;

    public int $rollBackCalls = 0;

    public function __construct(
        private bool $transactionActive = false,
        private ?Throwable $beginFailure = null,
        private ?Throwable $commitFailure = null,
        private ?Throwable $rollbackFailure = null,
    ) {
    }

    public function inTransaction(): bool
    {
        return $this->transactionActive;
    }

    public function beginTransaction(): bool
    {
        $this->beginTransactionCalls++;

        if ($this->beginFailure !== null) {
            throw $this->beginFailure;
        }

        $this->transactionActive = true;

        return true;
    }

    public function commit(): bool
    {
        $this->commitCalls++;

        if ($this->commitFailure !== null) {
            throw $this->commitFailure;
        }

        $this->transactionActive = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->rollBackCalls++;

        if ($this->rollbackFailure !== null) {
            throw $this->rollbackFailure;
        }

        $this->transactionActive = false;

        return true;
    }
}
