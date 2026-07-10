# Maatify Persistence - Package Reference

## Package Identity

* **Package Name**: `maatify/persistence`
* **Namespace**: `Maatify\Persistence`
* **Purpose**: Reusable persistence-layer utilities for Maatify projects.
* **Runtime Requirements**:
  * PHP >= 8.2
  * `ext-pdo`
* **Explicit Composer Dependencies**:
  * `maatify/exceptions` (`^1.0`)
* **Boundaries**: Framework-agnostic and host-agnostic. No HTTP API, no generic application repository, no ORM, no container bindings.

## Public API Inventory

### `Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig`
* **Status**: `final readonly class`
* **Constructor**:
  ```php
  public function __construct(
      string $table,
      ?string $scopeColumn = null,
      string $idColumn = 'id',
      string $orderColumn = 'display_order',
      ?string $deletedAtColumn = 'deleted_at'
  )
  ```
* **Public Properties**:
  * `string $table`
  * `?string $scopeColumn`
  * `string $idColumn`
  * `string $orderColumn`
  * `?string $deletedAtColumn`
* **Public Methods**:
  * `public function getQuotedTable(): string`
  * `public function getQuotedScopeColumn(): string`
  * `public function getQuotedIdColumn(): string`
  * `public function getQuotedOrderColumn(): string`
  * `public function getQuotedDeletedAtColumn(): string`
  * `public function isGlobal(): bool`
  * `public function hasSoftDelete(): bool`
* **Exceptions**: Throws `InvalidOrderingConfigurationException` on invalid identifiers.
* **Design rules**:
  * `table`: Supports `table` or `schema.table` formats.
  * Identifiers must be application-trusted constants.

### `Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager`
* **Status**: `final class`
* **Constructor**:
  ```php
  public function __construct()
  ```
* **Public Methods**:
  * `public function getNextPosition(\PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue): int`
    * Returns the next available position. Does not lock. Does not create transaction. No-op if table is empty.
    * Concurrency note: Use caller-owned lock for concurrent inserts.
  * `public function rowExistsInScope(\PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue, int|string $id): bool`
    * Returns boolean. Checks existence. Respects soft deletes.
  * `public function moveWithinScope(\PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue, int|string $id, int $newOrder): bool`
    * Locks rows (`SELECT ... FOR UPDATE`). Modifies data in self-owned transaction.
    * Throws `OrderingTransactionException` if PDO already has active transaction.
    * Throws `InvalidOrderingOperationException` if `newOrder < 1`.
    * Returns `true` if move succeeded or no-op. Returns `false` if target missing or update failed.
    * Rollback behavior: Rolls back and propagates original PDOException.
* **External Throwable Behavior**: Original `\Throwable` from `PDO` will be rethrown after rollback.

## SQL and Trust Boundaries

* **Identifiers**: SQL identifiers (table, columns) are validated configuration, not user input. They cannot be PDO-bound. Supported formats are standard identifier naming rules and `schema.table`.
* **Values**: All runtime values (ids, scope values) use prepared statements and PDO parameter binding.
* **No Host Assumptions**: No host schema assumptions or ORM dependencies.

## Exception Architecture

All package exceptions implement `Maatify\Persistence\Exception\PersistenceException`.
Distinguish these package exceptions from propagated `\PDOException` or other `\Throwable`.

| Exception | Maatify Base Class | Error Code | Safety Behavior | Triggering Conditions |
| --------- | ------------------ | ---------- | --------------- | --------------------- |
| `PersistenceException` | `\Throwable` | N/A | N/A | Interface implemented by all package exceptions. |
| `InvalidOrderingConfigurationException` | `SystemMaatifyException` | `ErrorCodeEnum::MAATIFY_ERROR` | default | Invalid/unsafe trusted SQL configuration identifiers. |
| `InvalidOrderingOperationException` | `ValidationMaatifyException` | `ErrorCodeEnum::INVALID_ARGUMENT` | default | Invalid runtime id, new order, or scope usage. |
| `OrderingTransactionException` | `UnsupportedMaatifyException` | `ErrorCodeEnum::UNSUPPORTED_OPERATION` | `defaultIsSafe(): false` | `moveWithinScope()` called with active PDO transaction. |

## Integration Requirements

* **Real MySQL Requirement**: Required for integration tests. No SQLite fallback.
* **Environment Variables**:
  * `PERSISTENCE_TEST_MYSQL_DSN`
  * `PERSISTENCE_TEST_MYSQL_USER`
  * `PERSISTENCE_TEST_MYSQL_PASSWORD`
* **Test Database Isolation**: Assumes isolated test tables and requires local package privileges (trigger/table cleanup). Tests include trigger failure injection.
* **Current CI MySQL Baseline**: 8.4.10.

## Verification Model

The test categories are:
* Unit
* Regression
* Integration (requires MySQL, tests rollback/failure injection)
* PHP compatibility matrices
* Lowest dependency compatibility
* CI Gate

## Non-goals

* No ORM
* No framework bindings
* No Host application container bindings
* No Host tables
* No HTTP layer
* No generic application repository abstraction
* No implicit transaction participation for `moveWithinScope()`
