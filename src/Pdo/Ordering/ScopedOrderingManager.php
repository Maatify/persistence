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
 * - getNextPosition() returns max(order) + 1.
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
     * If used for concurrent inserts, call it from a caller-owned transaction
     * with appropriate locking at the repository/application level.
     *
     * @param int|string|null $scopeValue Required when config has a non-null scope column; null means global ordering or the configured nullable scope.
     */
    public function getNextPosition(
        PDO $pdo,
        ScopedOrderingConfig $config,
        int|string|null $scopeValue = null
    ): int {
        $this->assertScopeUsage($config, $scopeValue);

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

        return ((int) $stmt->fetchColumn()) + 1;
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
            return (new PdoTransactionRunner())->run(
                $pdo,
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
