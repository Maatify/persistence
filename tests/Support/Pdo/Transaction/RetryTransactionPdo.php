<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\Pdo\Transaction;

use PDO;
use PDOException;
use Throwable;

/**
 * Scriptable PDO double for the deadlock-retry runners. Each beginTransaction()
 * and commit() call consumes the next scripted failure (null = succeed), each
 * exec() call the next scripted result (default 0) and records the statement,
 * and endTransactionOnServer() simulates InnoDB rolling back the whole
 * transaction of a deadlock victim. inTransaction() reports that server state
 * directly; a real pdo_mysql connection reports it only after its next
 * successful statement, which is why the runners probe with a no-op statement.
 * Like PDO, it refuses to begin inside an active transaction and to commit
 * without one. Test-only: it proves decision branches, never deadlock behavior
 * (that evidence comes from the real-MySQL integration suite).
 */
final class RetryTransactionPdo extends PDO
{
    public int $beginTransactionCalls = 0;

    public int $commitCalls = 0;

    public int $rollBackCalls = 0;

    /** @var list<string> */
    public array $executedStatements = [];

    /** @var list<Throwable|null> */
    private array $beginFailures;

    /** @var list<Throwable|null> */
    private array $commitFailures;

    /** @var list<int|false|Throwable> */
    private array $execResults;

    /**
     * @param list<Throwable|null>       $beginFailures
     * @param list<Throwable|null>       $commitFailures
     * @param list<int|false|Throwable>  $execResults
     */
    public function __construct(
        private bool $transactionActive = false,
        array $beginFailures = [],
        array $commitFailures = [],
        private ?Throwable $rollbackFailure = null,
        array $execResults = [],
    ) {
        $this->beginFailures = $beginFailures;
        $this->commitFailures = $commitFailures;
        $this->execResults = $execResults;
    }

    public function inTransaction(): bool
    {
        return $this->transactionActive;
    }

    public function beginTransaction(): bool
    {
        $this->beginTransactionCalls++;

        $failure = array_shift($this->beginFailures);
        if ($failure !== null) {
            throw $failure;
        }

        if ($this->transactionActive) {
            throw new PDOException('There is already an active transaction');
        }

        $this->transactionActive = true;

        return true;
    }

    public function commit(): bool
    {
        $this->commitCalls++;

        $failure = array_shift($this->commitFailures);
        if ($failure !== null) {
            throw $failure;
        }

        if (!$this->transactionActive) {
            throw new PDOException('There is no active transaction');
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

    public function exec(string $statement): int|false
    {
        $this->executedStatements[] = $statement;

        $result = array_shift($this->execResults) ?? 0;
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    /** Simulates InnoDB rolling back the whole transaction of a deadlock victim. */
    public function endTransactionOnServer(): void
    {
        $this->transactionActive = false;
    }
}
