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
* **Note**: PDO Pagination was introduced in v1.1.0.

## Public API Inventory

### `Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig`
* **Status**: `final readonly class`
* **Constructor**:
  ```php
  public function __construct(
      public string $table,
      public ?string $scopeColumn = null,
      public string $idColumn = 'id',
      public string $orderColumn = 'display_order',
      public ?string $deletedAtColumn = 'deleted_at'
  )
  ```
* **Public Properties**:
  * `string $table`
  * `?string $scopeColumn`
  * `string $idColumn`
  * `string $orderColumn`
  * `?string $deletedAtColumn`
* **Public Methods**:
  * `public function quotedTable(): string`
  * `public function quotedScopeColumn(): ?string`
  * `public function quotedIdColumn(): string`
  * `public function quotedOrderColumn(): string`
  * `public function quotedDeletedAtColumn(): ?string`
* **Exceptions**: Throws `InvalidOrderingConfigurationException` on invalid identifiers.
* **Design rules**:
  * `scopeColumn === null` represents global ordering.
  * `scopeColumn !== null` represents scoped ordering.
  * `deletedAtColumn === null` disables soft-delete filtering.
  * Nullable quoted methods return `null` when their configured column is disabled.
  * `table`: Supports `table` or `schema.table` formats.
  * Identifiers must be application-trusted constants.

### `Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager`
* **Status**: `final readonly class`
* **Constructor**: Stateless class with no explicitly declared constructor.
* **Public Methods**:
  * `public function getNextPosition(\PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue = null): int`
    * Returns `MAX(order) + 1` (calculated via `COALESCE(MAX(...), 0) + 1`).
    * Returns `1` for an empty applicable scope.
    * Ignores soft-deleted rows when soft-delete filtering is configured.
    * Does not begin a transaction.
    * Does not lock.
    * May throw `InvalidOrderingOperationException` for inconsistent scope usage.
    * External PDO/database throwables may propagate unchanged.
    * Concurrency note: Use caller-owned lock for concurrent inserts.
  * `public function rowExistsInScope(\PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue, int $id): bool`
    * Returns `false` for `id <= 0`.
    * Returns `false` when the row is absent from the requested scope.
    * Treats soft-deleted rows as absent when soft-delete filtering is configured.
    * May throw `InvalidOrderingOperationException` for inconsistent scope usage.
    * May propagate external PDO/database throwables unchanged.
  * `public function moveWithinScope(\PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue, int $id, int $newOrder): bool`
    * Rejects inconsistent scope usage (`InvalidOrderingOperationException`).
    * Throws `InvalidOrderingOperationException` for `id <= 0`.
    * Throws `InvalidOrderingOperationException` for `newOrder <= 0`.
    * Throws `OrderingTransactionException` when PDO already has an active transaction.
    * Owns its transaction.
    * Locks the applicable active scope.
    * Reads the target order inside the transaction.
    * Returns `false` if the target is missing.
    * Clamps above-maximum order to the applicable maximum.
    * Returns `true` for an already satisfied/clamped no-op.
    * Shifts only the affected range.
    * Does not globally normalize pre-existing gaps.
    * Returns `false` and rolls back if the final target update reports no affected row.
    * Rolls back an owned transaction when a throwable occurs after transaction startup.
    * Rethrows the same original throwable.
    * Does not universally wrap PDO failures as package exceptions.

### `Maatify\Persistence\Pdo\Pagination\PageRequest`
* **Status**: `final readonly class`
* **Constructor**:
  ```php
  public function __construct(
      public int|string|null $page = null,
      public int|string|null $perPage = null,
      public ?string $sortBy = null,
      public ?string $sortDirection = null
  )
  ```

### `Maatify\Persistence\Pdo\Pagination\SortDirectionEnum`
* **Status**: `enum SortDirectionEnum: string`
* **Cases**: `ASC = 'ASC'`, `DESC = 'DESC'`

### `Maatify\Persistence\Pdo\Pagination\SortWhitelist`
* **Status**: `final readonly class`
* **Constructor**:
  ```php
  public function __construct(array $sorts)
  ```
* **Methods**:
  * `public function contains(string $key): bool`
  * `public function quotedIdentifierFor(string $key): string`

### `Maatify\Persistence\Pdo\Pagination\PaginationConfig`
* **Status**: `final readonly class`
* **Constructor**:
  ```php
  public function __construct(
      public SortWhitelist $sortWhitelist,
      public string $defaultSortBy,
      public SortDirectionEnum $defaultSortDirection,
      public string $tieBreakerSortBy,
      public SortDirectionEnum $tieBreakerDirection,
      public int $defaultPerPage = 20,
      public int $minPerPage = 1,
      public int $maxPerPage = 200
  )
  ```

### `Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor`
* **Status**: `final readonly class`
* **Constructor**:
  ```php
  public function __construct(
      public string $totalSql,
      public array $totalParams,
      public string $filteredCountSql,
      public array $filteredCountParams,
      public string $dataSql,
      public array $dataParams
  )
  ```

### `Maatify\Persistence\Pdo\Pagination\PageResult`
* **Status**: `final readonly class implements JsonSerializable`
* **Constructor**:
  ```php
  public function __construct(
      public array $data,
      public int $page,
      public int $perPage,
      public int $total,
      public int $filtered,
      public int $totalPages,
      public bool $hasNext,
      public bool $hasPrevious,
      public string $sortBy,
      public SortDirectionEnum $sortDirection
  )
  ```
* **Methods**:
  * `public function toArray(): array`
  * `public function jsonSerialize(): array`
* **Serialized Envelope**:
  ```php
  [
      'data' => [],
      'pagination' => [
          'page' => 1,
          'per_page' => 20,
          'total' => 0,
          'filtered' => 0,
          'total_pages' => 0,
          'has_next' => false,
          'has_previous' => false,
          'sort_by' => 'created_at',
          'sort_direction' => 'DESC',
      ],
  ]
  ```

### `Maatify\Persistence\Pdo\Pagination\PdoPaginator`
* **Status**: `final readonly class`
* **Method**:
  ```php
  public function paginate(
      \PDO $pdo,
      PdoPaginationQueryDescriptor $query,
      PageRequest $request,
      PaginationConfig $config,
      callable $mapper
  ): PageResult
  ```
* **Transaction Boundary**: The paginator does not own a transaction. It works without one or within a caller-owned one without modifying its state.

### `Maatify\Persistence\Exception\InvalidPaginationConfigurationException`
* **Status**: `final class`
* **Extends**: `Maatify\Exceptions\Exception\System\SystemMaatifyException`
* **Implements**: `Maatify\Persistence\Exception\PersistenceException`
* **Trigger**: Invalid per-page bounds, whitelist errors.

### `Maatify\Persistence\Exception\InvalidPaginationQueryException`
* **Status**: `final class`
* **Extends**: `Maatify\Exceptions\Exception\System\SystemMaatifyException`
* **Implements**: `Maatify\Persistence\Exception\PersistenceException`
* **Trigger**: Missing/empty SQL, semicolons, reserved parameters.

### `Maatify\Persistence\Exception\PaginationExecutionException`
* **Status**: `final class`
* **Extends**: `Maatify\Exceptions\Exception\System\SystemMaatifyException`
* **Implements**: `Maatify\Persistence\Exception\PersistenceException`
* **Trigger**: Package-owned execution and result-contract failures, including non-throwing `prepare()`, `bindValue()`, or `execute()` failures, invalid count shape or count value, fetch-state failures, invalid mapper result, and invalid `PageResult` invariants. Thrown `PDOException` and mapper `Throwable` instances propagate without wrapping.

## SQL and Trust Boundaries

* **Identifiers**: SQL identifiers (table, columns) are validated configuration, not user input. They cannot be PDO-bound. Supported formats are standard identifier naming rules and `schema.table`.
* **Values**: All runtime values (ids, scope values) use prepared statements and PDO parameter binding.
* **No Host Assumptions**: No host schema assumptions or ORM dependencies.

## Pagination Boundaries
* **Normalization**: Strict page and per-page normalization.
* **Query Ownership**: Package handles total, filtered-count, and data query execution.
* **Caller Responsibility**: Caller owns SQL semantic alignment between total, filtered, and data queries.
* **Sorting**: Safe whitelist sorting and deterministic tie-breaker.
* **Mapper**: Mapper must return an array or object.
* **Transaction/PDO Attributes**: No transaction ownership, no PDO attribute mutation. Native MySQL prepared statements with emulation disabled are required.
* **Exclusions**: No SQL parser, query builder, filter builder, authorization, or HTTP behavior.

## Exception Architecture

All package exceptions implement `Maatify\Persistence\Exception\PersistenceException`.
Distinguish these package exceptions from propagated `\PDOException` or other `\Throwable`.

### Intentional `1.x` Marker Naming Exception

`Maatify\Persistence\Exception\PersistenceException` is intentionally retained as the package exception marker throughout the supported `1.x` release line.

The ecosystem naming standard requires interface names, including package exception marker interfaces, to end with the `Interface` suffix. This package published `PersistenceException` as part of its stable `v1.0.0` public API before that naming rule was fully enforced.

For the entire `1.x` line:

- the marker name MUST remain `PersistenceException`
- a parallel `PersistenceExceptionInterface` MUST NOT be introduced solely to normalize naming
- every package-defined exception, including exceptions added by new `1.x` features, MUST implement `PersistenceException` directly or indirectly
- consumers MAY continue to use `PersistenceException` as the package-wide catch boundary

This is a package-specific compatibility exception. It MUST NOT be copied into new packages or used as precedent for naming new interfaces.

Renaming the marker MAY be reconsidered only as part of a separately approved, meaningful future major release. A major release MUST NOT be created solely to rename this marker.

| Exception | Maatify Base Class | Error Code | Safety Behavior | Triggering Conditions |
| --------- | ------------------ | ---------- | --------------- | --------------------- |
| `PersistenceException` | `\Throwable` | N/A | N/A | Interface implemented by all package exceptions. |
| `InvalidOrderingConfigurationException` | `SystemMaatifyException` | `ErrorCodeEnum::MAATIFY_ERROR` | default | Invalid/unsafe trusted SQL configuration identifiers. |
| `InvalidOrderingOperationException` | `ValidationMaatifyException` | `ErrorCodeEnum::INVALID_ARGUMENT` | default | Invalid runtime id, new order, or scope usage. |
| `OrderingTransactionException` | `UnsupportedMaatifyException` | `ErrorCodeEnum::UNSUPPORTED_OPERATION` | `defaultIsSafe(): false` | `moveWithinScope()` called with active PDO transaction. |
| `InvalidPaginationConfigurationException` | `SystemMaatifyException` | `ErrorCodeEnum::MAATIFY_ERROR` | default | Invalid per-page bounds, whitelist errors. |
| `InvalidPaginationQueryException` | `SystemMaatifyException` | `ErrorCodeEnum::MAATIFY_ERROR` | default | Missing/empty SQL, semicolons, reserved parameters. |
| `PaginationExecutionException` | `SystemMaatifyException` | `ErrorCodeEnum::MAATIFY_ERROR` | default | Package-owned execution and result-contract failures. |
### `Maatify\Persistence\Exception\PersistenceException`
* **Status**: `interface`
* **Extends**: `\Throwable`
* **Description**: It is the package marker interface.
* **Public Methods**: It has no package-declared methods.
* **Rules**: It marks package-defined exceptions only. External PDO or infrastructure throwables are not required to implement it.

### `Maatify\Persistence\Exception\InvalidOrderingConfigurationException`
* **Status**: `final class`
* **Extends**: `Maatify\Exceptions\Exception\System\SystemMaatifyException`
* **Implements**: `Maatify\Persistence\Exception\PersistenceException`
* **Package-Declared Protected Method**:
  ```php
  protected function defaultErrorCode(): ErrorCodeInterface
  ```
  * Returns: `ErrorCodeEnum::MAATIFY_ERROR`
* **Public Methods**: No package-declared public methods beyond the inherited shared exception API.
* **Triggering Conditions**: Invalid trusted SQL configuration identifiers.

### `Maatify\Persistence\Exception\InvalidOrderingOperationException`
* **Status**: `final class`
* **Extends**: `Maatify\Exceptions\Exception\Validation\ValidationMaatifyException`
* **Implements**: `Maatify\Persistence\Exception\PersistenceException`
* **Package-Declared Protected Method**:
  ```php
  protected function defaultErrorCode(): ErrorCodeInterface
  ```
  * Returns: `ErrorCodeEnum::INVALID_ARGUMENT`
* **Public Methods**: No package-declared public methods beyond the inherited shared exception API.
* **Triggering Conditions**:
  * Invalid movement id
  * Invalid new ordering value
  * Inconsistent scope usage

### `Maatify\Persistence\Exception\OrderingTransactionException`
* **Status**: `final class`
* **Extends**: `Maatify\Exceptions\Exception\Unsupported\UnsupportedMaatifyException`
* **Implements**: `Maatify\Persistence\Exception\PersistenceException`
* **Package-Declared Protected Methods**:
  ```php
  protected function defaultErrorCode(): ErrorCodeInterface
  ```
  * Returns: `ErrorCodeEnum::UNSUPPORTED_OPERATION`

  ```php
  protected function defaultIsSafe(): bool
  ```
  * Returns: `false`
* **Public Methods**: No package-declared public methods beyond the inherited shared exception API.
* **Triggering Conditions**: Thrown when `moveWithinScope()` is called while PDO already has an active transaction.

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
