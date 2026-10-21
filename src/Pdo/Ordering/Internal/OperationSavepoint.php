<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Ordering\Internal;

use Maatify\Persistence\Exception\TransactionExecutionException;
use PDO;
use Throwable;

/**
 * Operation-local savepoint around the mutation window of a participating
 * moveWithinScope() call. It follows the ADR 0003 conventions of the savepoint
 * runners (name format, a control statement that returns false is a
 * TransactionExecutionException, the original Throwable wins over cleanup
 * failures, best-effort cleanup order) and differs only where the ordering
 * window needs it:
 * - undoOrFail() lets a failed ROLLBACK TO propagate, so that a `false` result
 *   is never returned over partial shifts;
 * - there is no owned-transaction path: the helper only exists while the
 *   caller owns the transaction;
 * - begin() refuses to start a mutation when the driver reports no active
 *   transaction after the SAVEPOINT reply (best effort only, never a proof of
 *   transaction identity).
 *
 * It never commits, fully rolls back or retries the caller's transaction.
 *
 * @internal Used only by ScopedOrderingManager; not part of the public API.
 */
final readonly class OperationSavepoint
{
    private const SAVEPOINT_PREFIX = 'maatify_persistence_sp_';

    private function __construct(private PDO $pdo, private string $name)
    {
    }

    /**
     * @throws TransactionExecutionException when SAVEPOINT returns false or the driver reports no active transaction afterwards
     */
    public static function begin(PDO $pdo): self
    {
        $savepoint = new self($pdo, self::SAVEPOINT_PREFIX . bin2hex(random_bytes(16)));
        $savepoint->executeControlStatement('SAVEPOINT ' . $savepoint->name);

        // A server-aborted caller transaction (1213, MariaDB 1020, 1205 with
        // innodb_rollback_on_timeout=ON) that the caller swallowed shows up here
        // on the measured engines; fail before the first mutation.
        if (!$pdo->inTransaction()) {
            throw new TransactionExecutionException('The caller-owned transaction is no longer active; the move was not started.');
        }

        return $savepoint;
    }

    /** After a Throwable in the window: best-effort ROLLBACK TO and RELEASE. The caller rethrows the original. */
    public function cleanupAfterFailure(): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }

        if (!$this->tryControlStatement('ROLLBACK TO SAVEPOINT ' . $this->name)) {
            return;
        }

        $this->tryControlStatement('RELEASE SAVEPOINT ' . $this->name);
    }

    /**
     * For a non-throwing failed window (the target write changed no row): ROLLBACK TO must succeed, otherwise its
     * failure propagates; RELEASE is best effort.
     *
     * @throws Throwable when ROLLBACK TO fails
     */
    public function undoOrFail(): void
    {
        $this->executeControlStatement('ROLLBACK TO SAVEPOINT ' . $this->name);
        $this->tryControlStatement('RELEASE SAVEPOINT ' . $this->name);
    }

    /**
     * Success path: RELEASE. If it fails, the window is undone best effort and the release failure is thrown.
     *
     * @throws Throwable when RELEASE fails
     */
    public function releaseOrUndo(): void
    {
        try {
            $this->executeControlStatement('RELEASE SAVEPOINT ' . $this->name);
        } catch (Throwable $releaseThrowable) {
            $this->cleanupAfterFailure();

            throw $releaseThrowable;
        }
    }

    private function executeControlStatement(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            throw new TransactionExecutionException('PDO transaction control statement failed.');
        }
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
