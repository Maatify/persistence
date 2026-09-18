<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\Pdo\Transaction;

use PDO;
use Throwable;

final class SavepointTransactionPdo extends PDO
{
    public int $beginTransactionCalls = 0;

    public int $commitCalls = 0;

    public int $rollBackCalls = 0;

    public int $execCalls = 0;

    /** @var list<string> */
    public array $executedStatements = [];

    /** @var list<int|false|Throwable> */
    private array $execResults;

    /**
     * @param list<int|false|Throwable> $execResults
     */
    public function __construct(
        private bool $transactionActive = false,
        array $execResults = [],
    ) {
        $this->execResults = $execResults;
    }

    public function inTransaction(): bool
    {
        return $this->transactionActive;
    }

    public function beginTransaction(): bool
    {
        $this->beginTransactionCalls++;
        $this->transactionActive = true;

        return true;
    }

    public function commit(): bool
    {
        $this->commitCalls++;
        $this->transactionActive = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->rollBackCalls++;
        $this->transactionActive = false;

        return true;
    }

    public function exec(string $statement): int|false
    {
        $this->execCalls++;
        $this->executedStatements[] = $statement;

        if ($this->execResults === []) {
            return 0;
        }

        $result = array_shift($this->execResults);
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
