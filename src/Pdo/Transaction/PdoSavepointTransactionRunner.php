<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Transaction;

use Maatify\Persistence\Exception\TransactionExecutionException;
use PDO;
use Throwable;

/**
 * Runs a callback with an operation-local savepoint inside an existing PDO
 * transaction and delegates transaction ownership to PdoTransactionRunner
 * when no transaction is active.
 */
final readonly class PdoSavepointTransactionRunner implements SavepointTransactionRunnerInterface
{
    private const SAVEPOINT_PREFIX = 'maatify_persistence_sp_';

    private PdoTransactionRunner $ownedTransactionRunner;

    public function __construct(private PDO $pdo)
    {
        $this->ownedTransactionRunner = new PdoTransactionRunner($pdo);
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
            return $this->ownedTransactionRunner->run($callback);
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

        $this->tryControlStatement('ROLLBACK TO SAVEPOINT ' . $savepointName);
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
