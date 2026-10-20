<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\Pdo\Transaction;

use PDO;
use PDOException;
use Throwable;

/**
 * Scriptable PDO double for the deadlock-retry runners that models how
 * pdo_mysql reports transaction state (observed on MySQL 8.4 / mysqlnd):
 * inTransaction() reflects the server state carried by the last SUCCESSFUL
 * reply, so after the error reply of a deadlock victim it still reports the
 * transaction as active until the next successful statement.
 *
 * - beginTransaction() / commit(): each call consumes the next scripted
 *   outcome: null = succeed, false = non-throwing failure (state unchanged),
 *   Throwable = thrown. Like PDO, it refuses to begin inside an active
 *   transaction and to commit without one.
 * - exec(): consumes the next scripted result (default 0) and records the
 *   statement; a successful result refreshes the reported state from the server.
 * - endTransactionOnServer(): InnoDB rolls back the whole transaction of a
 *   deadlock victim; the reported state stays stale.
 *
 * Test-only: it proves decision branches, never deadlock behavior (that
 * evidence comes from the real-MySQL integration suite).
 */
final class RetryTransactionPdo extends PDO
{
    public int $beginTransactionCalls = 0;

    public int $commitCalls = 0;

    public int $rollBackCalls = 0;

    /** @var list<string> */
    public array $executedStatements = [];

    private bool $serverTransactionActive;

    /** @var list<Throwable|false|null> */
    private array $beginOutcomes;

    /** @var list<Throwable|false|null> */
    private array $commitOutcomes;

    /** @var list<int|false|Throwable> */
    private array $execResults;

    /**
     * @param list<Throwable|false|null> $beginFailures
     * @param list<Throwable|false|null> $commitFailures
     * @param list<int|false|Throwable>  $execResults
     */
    public function __construct(
        private bool $transactionActive = false,
        array $beginFailures = [],
        array $commitFailures = [],
        private ?Throwable $rollbackFailure = null,
        array $execResults = [],
    ) {
        $this->serverTransactionActive = $transactionActive;
        $this->beginOutcomes = $beginFailures;
        $this->commitOutcomes = $commitFailures;
        $this->execResults = $execResults;
    }

    /** The state carried by the last successful reply (stale after an error reply). */
    public function inTransaction(): bool
    {
        return $this->transactionActive;
    }

    public function beginTransaction(): bool
    {
        $this->beginTransactionCalls++;

        $outcome = array_shift($this->beginOutcomes);
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        if ($outcome === false) {
            return false;
        }

        if ($this->transactionActive) {
            throw new PDOException('There is already an active transaction');
        }

        $this->setBothStates(true);

        return true;
    }

    public function commit(): bool
    {
        $this->commitCalls++;

        $outcome = array_shift($this->commitOutcomes);
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        if ($outcome === false) {
            return false;
        }

        if (!$this->transactionActive) {
            throw new PDOException('There is no active transaction');
        }

        $this->setBothStates(false);

        return true;
    }

    public function rollBack(): bool
    {
        $this->rollBackCalls++;

        if ($this->rollbackFailure !== null) {
            throw $this->rollbackFailure;
        }

        $this->setBothStates(false);

        return true;
    }

    public function exec(string $statement): int|false
    {
        $this->executedStatements[] = $statement;

        $result = array_shift($this->execResults) ?? 0;
        if ($result instanceof Throwable) {
            throw $result;
        }

        if ($result !== false) {
            $this->transactionActive = $this->serverTransactionActive;
        }

        return $result;
    }

    /**
     * Simulates InnoDB rolling back the whole transaction of a deadlock victim:
     * the server has no transaction any more, but the reported state stays
     * stale until the next successful statement, as with pdo_mysql.
     */
    public function endTransactionOnServer(): void
    {
        $this->serverTransactionActive = false;
    }

    public function isTransactionActiveOnServer(): bool
    {
        return $this->serverTransactionActive;
    }

    private function setBothStates(bool $active): void
    {
        $this->transactionActive = $active;
        $this->serverTransactionActive = $active;
    }
}
