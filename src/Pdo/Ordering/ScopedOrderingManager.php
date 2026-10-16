<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Ordering;

use Maatify\Persistence\Exception\InvalidOrderingOperationException;
use Maatify\Persistence\Pdo\Transaction\PdoTransactionRunner;
use PDO;
use Throwable;

/**
 * Manages integer ordering positions inside a table.
 *
 * The manager supports:
 * - global ordering: one sequence for the whole table
 * - scoped ordering: one sequence per scope value, e.g. per method_id/product_id
 * - nullable scoped ordering: one sequence for NULL and one sequence per non-null scope value
 *
 * Transaction model:
 * - moveWithinScope() uses the shared PDO transaction runner.
 * - It owns the transaction when called outside an active PDO transaction.
 * - It participates in an active caller-owned PDO transaction.
 *
 * Ordering model:
 * - getNextPosition() returns max(order) + 1 with a plain read and no lock.
 * - getNextPositionForUpdate() returns max(order) + 1 from the latest committed
 *   state using a locking read inside an active caller-owned PDO transaction.
 * - moveWithinScope() locks the whole ordering scope with SELECT ... FOR UPDATE,
 *   then reads the current row order from the database inside the transaction.
 * - newOrder is clamped to the current max position inside the same scope.
 */
final readonly class ScopedOrderingManager
{
    /**
     * Returns the next available order position within the configured scope.
     *
     * If the table has no rows in the scope, returns 1.
     * Soft-deleted rows are ignored when deletedAtColumn is configured.
     *
     * Note:
     * This method does not start a transaction and does not lock the scope.
     * Concurrent inserts need caller-side serialization, such as a lock held by
     * the caller's transaction.
     *
     * Snapshot rule: this plain read uses the transaction's consistent-read
     * snapshot. Under REPEATABLE READ that snapshot is created by the
     * transaction's first non-locking read, and locking reads (FOR UPDATE) do
     * not refresh it. Locking the scope or a parent row afterwards therefore
     * does not make this read current, and repeating it (for example to retry
     * after a duplicate-key error) returns the same stale value. The read is
     * current only when no older snapshot exists: outside a transaction, under
     * READ COMMITTED, or when the caller's serialization lock is taken before
     * the transaction's first non-locking read.
     *
     * Both next-position reads are supported. Use getNextPositionForUpdate() to
     * allocate a position for an insert inside a caller-owned transaction, in
     * particular under REPEATABLE READ; use this plain read for previews and
     * for the cases above where no older snapshot exists.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     */
    public function getNextPosition(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue = null
    ): int {
        $this->assertScopeUsage($config, $scopeValue);

        [$whereSql, $params] = $this->buildScopeWhere($config, $scopeValue);

        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(' . $config->quotedOrderColumn() . '), 0)
               FROM ' . $config->quotedTable()
            . $whereSql
        );

        $stmt->execute($params);

        return ((int) $stmt->fetchColumn()) + 1;
    }

    /**
     * Returns the next available order position from the latest committed state,
     * using a locking read inside the caller-owned transaction.
     *
     * Requires an active PDO transaction on the given connection and never
     * begins, commits, or rolls back one; the lock is held until the caller ends
     * the transaction, so perform the insert on the same connection and
     * transaction. A locking read (SELECT ... FOR UPDATE ... ORDER BY order DESC,
     * id DESC LIMIT 1) returns the latest committed state instead of the
     * transaction's REPEATABLE READ snapshot. The row it selects and locks is the
     * last active row by the configured order column, with the configured id
     * column as the tie-breaker.
     *
     * The locking read is repeated until two consecutive reads return the same
     * order value, so every call issues at least two locking reads, including
     * for an empty scope. A read that waited for the lock on the previous tail
     * may resume after another transaction committed a higher tail (observable
     * under READ COMMITTED); the repeat makes the returned position follow that
     * tail. The repeat continues only while the tail keeps changing, and each
     * repeated read can itself wait for a lock or fail as described below.
     *
     * Guarantee: the returned position reflects the latest committed state, and
     * on a non-empty scope concurrent callers that also use locking reads (this
     * method, moveWithinScope()) are serialized on the scope's last active row.
     *
     * Limits:
     * - An empty scope has no row to lock, so concurrent first inserts are not
     *   serialized; the caller must serialize them (e.g. lock a parent row or
     *   rely on a UNIQUE constraint).
     * - Lock footprint depends on the access plan. With an index usable for
     *   (scope, order) the scan stops at the last active row; every soft-deleted
     *   row above it is scanned and locked as well. Without such an index the
     *   scan covers, and locks, every row of the table.
     * - Under REPEATABLE READ the lock also covers the gap above the last
     *   scanned row up to the next index record, which may belong to another
     *   scope. Under READ COMMITTED no gap lock is taken.
     * - Writers that do not use locking reads are not serialized, except by the
     *   REPEATABLE READ gap lock described above.
     * - The call may wait for locks and fail with a lock wait timeout or
     *   deadlock PDOException, which propagates unchanged; retrying is the
     *   caller's responsibility. Under REPEATABLE READ, a call queued behind a
     *   transaction that then inserts above the locked row typically fails with
     *   a deadlock instead of resuming.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     *
     * @throws InvalidOrderingOperationException When no PDO transaction is active or scope usage is inconsistent.
     * @throws Throwable When a PDO/database operation fails.
     */
    public function getNextPositionForUpdate(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue = null
    ): int {
        $this->assertScopeUsage($config, $scopeValue);

        if (!$pdo->inTransaction()) {
            throw new InvalidOrderingOperationException(
                'A locking next-position read requires an active PDO transaction.'
            );
        }

        [$whereSql, $params] = $this->buildScopeWhere($config, $scopeValue);

        // ORDER BY ... DESC LIMIT 1 lets InnoDB stop at the last active row when
        // an (scope, order) index exists. MAX(...) FOR UPDATE would lock every
        // row of the scanned scope range instead.
        $stmt = $pdo->prepare(
            'SELECT ' . $config->quotedOrderColumn() . '
               FROM ' . $config->quotedTable()
            . $whereSql .
            ' ORDER BY ' . $config->quotedOrderColumn() . ' DESC, ' . $config->quotedIdColumn() . ' DESC
              LIMIT 1
              FOR UPDATE'
        );

        // A read that waited for the lock on the previous tail can resume after
        // another transaction committed a higher tail (observable under READ
        // COMMITTED). Repeat the locking read until it returns the same order
        // value twice, so the position is derived from a tail this transaction
        // holds.
        $previousTail = null;

        while (true) {
            $stmt->execute($params);

            $value = $stmt->fetchColumn();
            $stmt->closeCursor();

            $tail = $value === false ? 0 : (int) $value;

            if ($tail === $previousTail) {
                return $tail + 1;
            }

            $previousTail = $tail;
        }
    }

    /**
     * Moves a row inside its ordering scope.
     *
     * This method does NOT trust a caller-provided current order. It locks the
     * whole ordering scope first, then reads the current order directly from
     * the database inside the same transaction.
     *
     * The scope lock serializes concurrent reorder operations within the same
     * scope and prevents overlapping range updates from interleaving.
     *
     * If $newOrder is larger than the current maximum order in the scope, it is
     * clamped to the maximum position. This keeps ordering positions contiguous
     * instead of creating large gaps.
     *
     * Return values:
     * - true when the move succeeds.
     * - true when the target row is already at the requested/clamped position.
     * - false when the target row does not exist in the given scope.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     * @param string|null $updatedAtValue Timestamp value bound to the target update when updatedAtColumn is configured.
     *
     * @throws InvalidOrderingOperationException When id/order/scope arguments are invalid.
     * @throws Throwable When a PDO/database operation fails.
     */
    public function moveWithinScope(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
        int $newOrder,
        ?string $updatedAtValue = null,
    ): bool {
        $this->assertScopeUsage($config, $scopeValue);
        $this->assertUpdatedAtUsage($config, $updatedAtValue);

        if ($id <= 0) {
            throw new InvalidOrderingOperationException('Invalid row id.');
        }

        if ($newOrder <= 0) {
            throw new InvalidOrderingOperationException('New ordering position must be a positive integer.');
        }

        // Preserve the existing false-and-rollback contract through the shared runner.
        $rollbackResult = new class () extends \RuntimeException {
        };

        try {
            return (new PdoTransactionRunner($pdo))->run(
                function () use ($pdo, $config, $scopeValue, $id, $newOrder, $updatedAtValue, $rollbackResult): bool {
                    $this->lockScopeForUpdate($pdo, $config, $scopeValue);

                    $currentOrder = $this->getCurrentOrderForUpdate($pdo, $config, $scopeValue, $id);

                    if ($currentOrder === null) {
                        throw $rollbackResult;
                    }

                    $maxOrder = $this->getMaxPosition($pdo, $config, $scopeValue);
                    $newOrder = min($newOrder, max(1, $maxOrder));

                    if ($currentOrder === $newOrder) {
                        return true;
                    }

                    if ($newOrder < $currentOrder) {
                        $this->shiftOrdersUp($pdo, $config, $scopeValue, $newOrder, $currentOrder);
                    } else {
                        $this->shiftOrdersDown($pdo, $config, $scopeValue, $newOrder, $currentOrder);
                    }

                    $updated = $this->updateTargetOrder(
                        $pdo,
                        $config,
                        $scopeValue,
                        $id,
                        $newOrder,
                        $updatedAtValue,
                    );

                    if (!$updated) {
                        throw $rollbackResult;
                    }

                    return true;
                },
            );
        } catch (Throwable $throwable) {
            if ($throwable === $rollbackResult) {
                return false;
            }

            throw $throwable;
        }
    }

    /**
     * Checks whether a row exists inside the configured scope.
     *
     * Soft-deleted rows are treated as non-existing when deletedAtColumn is configured.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     */
    public function rowExistsInScope(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id
    ): bool {
        $this->assertScopeUsage($config, $scopeValue);

        if ($id <= 0) {
            return false;
        }

        $where = [];
        $params = ['id' => $id];

        $where[] = $config->quotedIdColumn() . ' = :id';
        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        $stmt = $pdo->prepare(
            'SELECT 1
               FROM ' . $config->quotedTable() . '
              WHERE ' . implode(' AND ', $where) . '
              LIMIT 1'
        );

        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Locks all active rows in the configured ordering scope.
     *
     * This serializes concurrent reorder operations within the same scope and
     * prevents overlapping range updates from interleaving.
     *
     * For global ordering, this locks all active rows in the table.
     * For scoped ordering, this locks only rows matching the scope value.
     *
     * Soft-deleted rows are ignored when deletedAtColumn is configured.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     */
    private function lockScopeForUpdate(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue
    ): void {
        $where = [];
        $params = [];

        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare(
            'SELECT ' . $config->quotedIdColumn() . '
               FROM ' . $config->quotedTable()
            . $whereSql .
            ' ORDER BY ' . $config->quotedOrderColumn() . ' ASC, ' . $config->quotedIdColumn() . ' ASC
              FOR UPDATE'
        );

        $stmt->execute($params);
    }

    /**
     * Reads and locks the target row order.
     *
     * The ordering scope is already locked by lockScopeForUpdate(). This extra
     * FOR UPDATE keeps the target-row read explicit and safe if this method is
     * changed or reused later.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     */
    private function getCurrentOrderForUpdate(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id
    ): ?int {
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

    /**
     * Returns the current maximum order position inside the scope.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     */
    private function getMaxPosition(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue
    ): int {
        $where = [];
        $params = [];

        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(' . $config->quotedOrderColumn() . '), 0)
               FROM ' . $config->quotedTable()
            . $whereSql
        );

        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Shifts rows up when moving the target row to a smaller order value.
     *
     * Example:
     * current = 5, new = 2
     * rows 2..4 become 3..5.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     */
    private function shiftOrdersUp(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $newOrder,
        int $currentOrder
    ): void {
        $where = [];
        $params = [
            'new_order_where' => $newOrder,
            'current_order' => $currentOrder,
        ];

        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        $where[] = $config->quotedOrderColumn() . ' >= :new_order_where';
        $where[] = $config->quotedOrderColumn() . ' < :current_order';

        $stmt = $pdo->prepare(
            'UPDATE ' . $config->quotedTable() . '
                SET ' . $config->quotedOrderColumn() . ' = ' . $config->quotedOrderColumn() . ' + 1
              WHERE ' . implode(' AND ', $where)
        );

        $stmt->execute($params);
    }

    /**
     * Shifts rows down when moving the target row to a larger order value.
     *
     * Example:
     * current = 2, new = 5
     * rows 3..5 become 2..4.
     *
     * @param int|string|null $scopeValue Required when config has a scope column; null for global ordering.
     */
    private function shiftOrdersDown(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $newOrder,
        int $currentOrder
    ): void {
        $where = [];
        $params = [
            'new_order_where' => $newOrder,
            'current_order' => $currentOrder,
        ];

        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        $where[] = $config->quotedOrderColumn() . ' <= :new_order_where';
        $where[] = $config->quotedOrderColumn() . ' > :current_order';

        $stmt = $pdo->prepare(
            'UPDATE ' . $config->quotedTable() . '
                SET ' . $config->quotedOrderColumn() . ' = ' . $config->quotedOrderColumn() . ' - 1
              WHERE ' . implode(' AND ', $where)
        );

        $stmt->execute($params);
    }

    /**
     * Updates the target row to its final order value.
     *
     * @param int|string|null $scopeValue Required when config has a scope column; null for global ordering.
     */
    private function updateTargetOrder(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        int $id,
        int $newOrder,
        ?string $updatedAtValue
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
     * Builds the scope and soft-delete WHERE clause shared by position reads.
     *
     * @param int|string|null $scopeValue Scope value for scoped ordering.
     *
     * @return array{0: string, 1: array<string, int|string|null>} WHERE SQL (empty or with leading space) and bound parameters.
     */
    private function buildScopeWhere(ScopedOrderingConfig $config, int|string|null $scopeValue): array
    {
        $where = [];
        $params = [];

        $this->appendScopeCondition($config, $scopeValue, $where, $params);
        $this->appendSoftDeleteCondition($config, $where);

        return [$where !== [] ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }

    /**
     * Appends the configured scope condition to a WHERE clause.
     *
     * If config has no scope column, this method intentionally does nothing.
     *
     * @param int|string|null                $scopeValue Scope value for scoped ordering; NULL is supported when nullableScope is enabled.
     * @param list<string>                   $where      WHERE clause fragments.
     * @param array<string, int|string|null> $params     Bound parameter map.
     */
    private function appendScopeCondition(
        ScopedOrderingConfig $config,
        int|string|null $scopeValue,
        array &$where,
        array &$params
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
     * Appends a soft-delete condition when configured.
     *
     * If deletedAtColumn is null, soft-delete filtering is disabled.
     *
     * @param list<string> $where WHERE clause fragments.
     */
    private function appendSoftDeleteCondition(
        ScopedOrderingConfig $config,
        array &$where
    ): void {
        $deletedAtColumn = $config->quotedDeletedAtColumn();

        if ($deletedAtColumn === null) {
            return;
        }

        $where[] = $deletedAtColumn . ' IS NULL';
    }

    /**
     * Ensures that scope configuration and scope value are used consistently.
     *
     * Valid:
     * - scopeColumn = null, scopeValue = null
     * - scopeColumn = "method_id", scopeValue = 10
     * - scopeColumn = "parent_id", nullableScope = true, scopeValue = null
     *
     * Invalid:
     * - scopeColumn = null, scopeValue = 10
     * - scopeColumn = "method_id", nullableScope = false, scopeValue = null
     *
     * @param int|string|null $scopeValue Scope value for scoped ordering.
     */
    private function assertScopeUsage(
        ScopedOrderingConfig $config,
        int|string|null $scopeValue
    ): void {
        if ($config->scopeColumn === null && $scopeValue !== null) {
            throw new InvalidOrderingOperationException('Scope value was provided without a scope column.');
        }

        if ($config->scopeColumn !== null && $scopeValue === null && !$config->nullableScope) {
            throw new InvalidOrderingOperationException('Scope column requires a scope value.');
        }
    }

    /**
     * Ensures that timestamp configuration and the operation value are used
     * consistently.
     *
     * @param string|null $updatedAtValue Timestamp value for the target mutation.
     */
    private function assertUpdatedAtUsage(
        ScopedOrderingConfig $config,
        ?string $updatedAtValue
    ): void {
        if ($config->updatedAtColumn === null && $updatedAtValue !== null) {
            throw new InvalidOrderingOperationException(
                'An updated-at value was provided without an updated-at column.'
            );
        }

        if ($config->updatedAtColumn !== null && $updatedAtValue === null) {
            throw new InvalidOrderingOperationException(
                'An updated-at column requires an updated-at value.'
            );
        }
    }
}
