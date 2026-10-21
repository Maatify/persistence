<?php

declare(strict_types=1);

namespace Maatify\Persistence\Study48;

use Maatify\Persistence\Exception\InvalidOrderingOperationException;
use Maatify\Persistence\Exception\TransactionExecutionException;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner;
use PDO;
use Throwable;

/**
 * Issue #48 STUDY PROTOTYPE. Not package runtime, not autoloaded by Composer, not shipped.
 *
 * Models the recommended design: moveWithinScope() inside a caller-owned transaction wraps its mutation window
 * (the range shift and the target write) in ONE operation-local savepoint that is created lazily, right before
 * the first mutating statement. Everything before that point is read-only (scope lock, target lookup, clamp,
 * no-op check), so a missing row or a no-op move issues no savepoint statement at all.
 *
 * Without an active transaction the call is delegated untouched to the shipped ScopedOrderingManager (the
 * package-owned path is byte-for-byte the v1.4.0 behavior). The SQL helpers below are a copy of the shipped
 * private helpers so the prototype can be compared with the real manager; the differential probe (s4) checks
 * that both produce identical results.
 *
 * Invariants the design must keep (each is probed):
 * - the callback Throwable is always the visible failure; cleanup failures never replace it;
 * - never commits, fully rolls back or retries the caller-owned transaction;
 * - no cleanup success is claimed or assumed: ROLLBACK TO / RELEASE are best effort after a Throwable;
 * - a `false` outcome after the shifts is returned only if the shifts were provably undone, otherwise it fails closed.
 */
final readonly class SavepointMovePrototype
{
    private const SAVEPOINT_PREFIX = 'maatify_persistence_sp_';

    /**
     * @param 'inline'|'runner' $window 'inline' (recommended): savepoint statements owned by this class (a few
     *        @internal control helpers in an implementation). 'runner': study variant that reuses the existing
     *        PdoDeadlockRetrySavepointTransactionRunner for the mutation window, to measure what reuse costs.
     */
    public function __construct(private ScopedOrderingManager $owned = new ScopedOrderingManager(), private string $window = 'inline')
    {
    }

    /**
     * @param int|string|null $scopeValue
     */
    public function moveWithinScope(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
        int $newOrder,
        ?string $updatedAtValue = null,
    ): bool {
        if (!$pdo->inTransaction()) {
            return $this->owned->moveWithinScope($pdo, $config, $scopeValue, $id, $newOrder, $updatedAtValue);
        }

        $this->assertScopeUsage($config, $scopeValue);
        $this->assertUpdatedAtUsage($config, $updatedAtValue);

        if ($id <= 0) {
            throw new InvalidOrderingOperationException('Invalid row id.');
        }

        if ($newOrder <= 0) {
            throw new InvalidOrderingOperationException('New ordering position must be a positive integer.');
        }

        // Read-only phase: failures here leave nothing to undo, so no savepoint exists yet.
        $maxOrder = $this->lockScopeForUpdate($pdo, $config, $scopeValue);
        $currentOrder = $this->getCurrentOrderForUpdate($pdo, $config, $scopeValue, $id);

        if ($currentOrder === null) {
            return false;
        }

        $newOrder = min($newOrder, max(1, $maxOrder));

        if ($currentOrder === $newOrder) {
            return true;
        }

        if ($this->window === 'runner') {
            return $this->mutateThroughRunner($pdo, $config, $scopeValue, $id, $newOrder, $currentOrder, $updatedAtValue);
        }

        // Mutation window: from here on a partial result is possible.
        $savepoint = self::SAVEPOINT_PREFIX . bin2hex(random_bytes(16));
        $this->executeControlStatement($pdo, 'SAVEPOINT ' . $savepoint);

        // Best-effort detection only, NOT a proof of transaction identity (F54-01). On the engines measured here
        // (MySQL 8.0.46/8.4.10, MariaDB 11.8.9) a swallowed 1213/1020 leaves no transaction and the successful
        // SAVEPOINT reply makes the driver report it; then fail before the first mutation. On MariaDB 11.4.7 the
        // server opens a NEW implicit transaction, the driver reports "active", and this check cannot tell it from
        // the caller's original transaction. The rule stays: a caller must not swallow 1213/1020 and continue.
        if (!$pdo->inTransaction()) {
            throw new TransactionExecutionException('The caller-owned transaction is no longer active; the move was not started.');
        }

        try {
            if ($newOrder < $currentOrder) {
                $this->shiftOrders($pdo, $config, $scopeValue, $newOrder, $currentOrder, '+', '>=', '<');
            } else {
                $this->shiftOrders($pdo, $config, $scopeValue, $newOrder, $currentOrder, '-', '<=', '>');
            }

            $updated = $this->updateTargetOrder($pdo, $config, $scopeValue, $id, $newOrder, $updatedAtValue);
        } catch (Throwable $throwable) {
            $this->cleanupAfterFailure($pdo, $savepoint);

            throw $throwable;
        }

        if (!$updated) {
            // `false` promises "nothing changed". There is no original Throwable to protect here, so a failed
            // undo must surface (its Throwable propagates) instead of being reported as `false`.
            $this->executeControlStatement($pdo, 'ROLLBACK TO SAVEPOINT ' . $savepoint);
            $this->tryControlStatement($pdo, 'RELEASE SAVEPOINT ' . $savepoint);

            return false;
        }

        try {
            $this->executeControlStatement($pdo, 'RELEASE SAVEPOINT ' . $savepoint);
        } catch (Throwable $releaseThrowable) {
            $this->cleanupAfterFailure($pdo, $savepoint);

            throw $releaseThrowable;
        }

        return true;
    }

    /** Study variant: the mutation window runs inside the existing savepoint runner, unchanged. */
    private function mutateThroughRunner(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
        int $newOrder,
        int $currentOrder,
        ?string $updatedAtValue,
    ): bool {
        $noRow = new class () extends \RuntimeException {
        };

        try {
            return (new PdoDeadlockRetrySavepointTransactionRunner($pdo))->run(
                function () use ($pdo, $config, $scopeValue, $id, $newOrder, $currentOrder, $updatedAtValue, $noRow): bool {
                    if (!$pdo->inTransaction()) {
                        throw new TransactionExecutionException('The caller-owned transaction is no longer active; the move was not started.');
                    }

                    if ($newOrder < $currentOrder) {
                        $this->shiftOrders($pdo, $config, $scopeValue, $newOrder, $currentOrder, '+', '>=', '<');
                    } else {
                        $this->shiftOrders($pdo, $config, $scopeValue, $newOrder, $currentOrder, '-', '<=', '>');
                    }

                    if (!$this->updateTargetOrder($pdo, $config, $scopeValue, $id, $newOrder, $updatedAtValue)) {
                        throw $noRow;
                    }

                    return true;
                },
            );
        } catch (Throwable $throwable) {
            if ($throwable === $noRow) {
                return false;
            }

            throw $throwable;
        }
    }

    private function cleanupAfterFailure(PDO $pdo, string $savepoint): void
    {
        if (!$pdo->inTransaction()) {
            return;
        }

        if (!$this->tryControlStatement($pdo, 'ROLLBACK TO SAVEPOINT ' . $savepoint)) {
            return;
        }

        $this->tryControlStatement($pdo, 'RELEASE SAVEPOINT ' . $savepoint);
    }

    private function executeControlStatement(PDO $pdo, string $sql): void
    {
        if ($pdo->exec($sql) === false) {
            throw new TransactionExecutionException('PDO transaction control statement failed.');
        }
    }

    private function tryControlStatement(PDO $pdo, string $sql): bool
    {
        try {
            $this->executeControlStatement($pdo, $sql);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    // ---- copy of the shipped private helpers (kept textually close for the differential probe) ----

    private function lockScopeForUpdate(PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue): int
    {
        $where = [];
        $params = [];

        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare(
            'SELECT ' . $config->quotedIdColumn() . ', ' . $config->quotedOrderColumn() . '
               FROM ' . $config->quotedTable()
            . $whereSql .
            ' ORDER BY ' . $config->quotedOrderColumn() . ' ASC, ' . $config->quotedIdColumn() . ' ASC
              FOR UPDATE'
        );

        $stmt->execute($params);

        $maxOrder = 0;

        while (($order = $stmt->fetchColumn(1)) !== false) {
            $maxOrder = (int) $order;
        }

        $stmt->closeCursor();

        return $maxOrder;
    }

    private function getCurrentOrderForUpdate(PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue, int $id): ?int
    {
        $where = [];
        $params = ['id' => $id];

        $where[] = $config->quotedIdColumn() . ' = :id';
        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        $stmt = $pdo->prepare(
            'SELECT ' . $config->quotedOrderColumn() . '
               FROM ' . $config->quotedTable() . '
              WHERE ' . implode(' AND ', $where) . '
              LIMIT 1
              FOR UPDATE'
        );

        $stmt->execute($params);

        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    private function shiftOrders(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $newOrder,
        int $currentOrder,
        string $delta,
        string $newOrderOp,
        string $currentOrderOp,
    ): void {
        $where = [];
        $params = [
            'new_order_where' => $newOrder,
            'current_order' => $currentOrder,
        ];

        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        $where[] = $config->quotedOrderColumn() . ' ' . $newOrderOp . ' :new_order_where';
        $where[] = $config->quotedOrderColumn() . ' ' . $currentOrderOp . ' :current_order';

        $stmt = $pdo->prepare(
            'UPDATE ' . $config->quotedTable() . '
                SET ' . $config->quotedOrderColumn() . ' = ' . $config->quotedOrderColumn() . ' ' . $delta . ' 1
              WHERE ' . implode(' AND ', $where)
        );

        $stmt->execute($params);
    }

    private function updateTargetOrder(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
        int $newOrder,
        ?string $updatedAtValue,
    ): bool {
        $where = [];
        $params = [
            'id'             => $id,
            'new_order_case' => $newOrder,
        ];

        $where[] = $config->quotedIdColumn() . ' = :id';
        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        $set = $config->quotedOrderColumn() . ' = :new_order_case';
        $updatedAtColumn = $config->quotedUpdatedAtColumn();
        if ($updatedAtColumn !== null) {
            $set .= ', ' . $updatedAtColumn . ' = :updated_at_value';
            $params['updated_at_value'] = $updatedAtValue;
        }

        $stmt = $pdo->prepare(
            'UPDATE ' . $config->quotedTable() . '
                SET ' . $set . '
              WHERE ' . implode(' AND ', $where)
        );

        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param list<string>                   $where
     * @param array<string, int|string|null> $params
     */
    private function appendScopeCondition(
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        array &$where,
        array &$params,
    ): void {
        $scopeColumn = $config->quotedScopeColumn();

        if ($scopeColumn === null) {
            return;
        }

        if ($scopeValue === null && !$config->nullableScope) {
            throw new InvalidOrderingOperationException('Scope column requires a scope value.');
        }

        if ($scopeValue === null) {
            $where[] = $scopeColumn . ' IS NULL';

            return;
        }

        $where[] = $scopeColumn . ' = :scope_value';
        $params['scope_value'] = $scopeValue;
    }

    /**
     * @param list<string> $where
     */
    private function appendSoftDeleteCondition(ScopedOrderingConfig $config, array &$where): void
    {
        $deletedAtColumn = $config->quotedDeletedAtColumn();

        if ($deletedAtColumn === null) {
            return;
        }

        $where[] = $deletedAtColumn . ' IS NULL';
    }

    private function assertScopeUsage(ScopedOrderingConfig $config, int|string|null $scopeValue): void
    {
        if ($config->scopeColumn === null && $scopeValue !== null) {
            throw new InvalidOrderingOperationException('Scope value was provided without a scope column.');
        }

        if ($config->scopeColumn !== null && $scopeValue === null && !$config->nullableScope) {
            throw new InvalidOrderingOperationException('Scope column requires a scope value.');
        }
    }

    private function assertUpdatedAtUsage(ScopedOrderingConfig $config, ?string $updatedAtValue): void
    {
        if ($config->updatedAtColumn === null && $updatedAtValue !== null) {
            throw new InvalidOrderingOperationException('An updated-at value was provided without an updated-at column.');
        }

        if ($config->updatedAtColumn !== null && $updatedAtValue === null) {
            throw new InvalidOrderingOperationException('An updated-at column requires an updated-at value.');
        }
    }
}
