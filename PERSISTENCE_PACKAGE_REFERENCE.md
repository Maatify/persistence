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

## Persistence and Schema Ownership

The package owns the reusable PDO ordering, transaction, savepoint, and
pagination behavior, but it does not own a persistent business entity or a
production table. Consumers provide their own trusted table and column
identifiers, SQL, scopes, and mapping. The package does not create migrations,
foreign keys, or joins to Host tables. The persistence profile is
MySQL/MariaDB-compatible SQL through direct PDO. Repository integration and
Consumer verification run against real MySQL, with MySQL 8.4.10 as the current
CI baseline; MariaDB is not independently verified by the current CI matrix.
The package-level schema notes are in [schema/README.md](schema/README.md).

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
      public ?string $deletedAtColumn = 'deleted_at',
      public bool $nullableScope = false,
      public ?string $updatedAtColumn = null
  )
  ```
* **Public Properties**:
  * `string $table`
  * `?string $scopeColumn`
  * `string $idColumn`
  * `string $orderColumn`
  * `?string $deletedAtColumn`
  * `bool $nullableScope`
  * `?string $updatedAtColumn`
* **Public Methods**:
  * `public function quotedTable(): string`
  * `public function quotedScopeColumn(): ?string`
  * `public function quotedIdColumn(): string`
  * `public function quotedOrderColumn(): string`
  * `public function quotedDeletedAtColumn(): ?string`
  * `public function quotedUpdatedAtColumn(): ?string`
* **Exceptions**: Throws `InvalidOrderingConfigurationException` on invalid identifiers.
* **Design rules**:
  * `scopeColumn === null` represents global ordering.
  * `scopeColumn !== null` represents scoped ordering.
  * `nullableScope === true` permits `scopeValue === null` to mean `scopeColumn IS NULL`; it is valid only with a configured scope column.
  * `deletedAtColumn === null` disables soft-delete filtering.
  * `updatedAtColumn !== null` enables an atomic target-row timestamp update for successful moves.
  * Nullable quoted methods return `null` when their configured column is disabled.
  * `table`: Supports `table` or `schema.table` formats.
  * Identifiers must be application-trusted constants.

### `Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager`
* **Status**: `final readonly class`
* **Constructor**: Stateless class with no explicitly declared constructor.
* **Public Methods**:
  * `public function getNextPosition(\PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue = null): int`
    * Supported lock-free read, intended for previews and for allocation only when no older snapshot exists (outside a transaction, under `READ COMMITTED`, or with the caller's serialization lock, which every competing writer takes first (for example on a parent row), taken before the transaction's first non-locking read); use `getNextPositionForUpdate()` to allocate a position inside a caller-owned transaction.
    * Returns `MAX(order) + 1` (calculated via `COALESCE(MAX(...), 0) + 1`).
    * Returns `1` for an empty applicable scope.
    * Ignores soft-deleted rows when soft-delete filtering is configured.
    * Does not begin a transaction.
    * Does not lock.
    * May throw `InvalidOrderingOperationException` for inconsistent scope usage.
    * External PDO/database throwables may propagate unchanged.
    * Concurrency note: concurrent inserts need caller-side serialization, such as a lock held by the caller's transaction.
    * Snapshot rule: under `REPEATABLE READ` the plain read uses the snapshot created by the transaction's first non-locking read, and locking reads do not refresh it. After a later commit by another connection it can return a stale maximum even if the caller locked the scope or a parent row afterwards, and repeating it in the same transaction returns the same stale value; use `getNextPositionForUpdate()` or retry in a new transaction.
  * `public function getNextPositionForUpdate(\PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue = null): int`
    * Returns `MAX(order) + 1` from the latest committed state (`1` for an empty applicable scope); soft-deleted rows are ignored when soft-delete filtering is configured.
    * Requires an active PDO transaction on the connection; throws `InvalidOrderingOperationException` when none is active. Never begins, commits, or rolls back a transaction.
    * Performs `SELECT order ... ORDER BY order DESC, id DESC LIMIT 1 FOR UPDATE` (configured order and id columns); the lock is held until the caller ends the transaction. The locking read is repeated until two consecutive reads return the same order value, so every call issues at least two locking reads (also for an empty scope), and a call that waited for the lock on the previous tail follows a higher tail committed meanwhile (observable under `READ COMMITTED`). The repeat continues only while the tail keeps changing, and each repeated read can itself wait for a lock or fail with a lock wait timeout or deadlock.
    * May throw `InvalidOrderingOperationException` for inconsistent scope usage.
    * External PDO/database throwables, including lock wait timeout and deadlock errors, may propagate unchanged; retrying is the caller's responsibility. Under `REPEATABLE READ`, a call queued behind a transaction that then inserts above the locked row typically fails with a deadlock instead of resuming.
    * Guarantee: on a non-empty scope, callers that also use locking reads (this method, `moveWithinScope()`) are serialized on the scope's last active row.
    * Limits: an empty scope has no row to lock, so the caller must serialize the first insert. Lock footprint depends on the access plan (an index usable for `(scope, order)` stops at the last active row but also locks soft-deleted rows above it; without one, every table row is scanned and locked). Under `REPEATABLE READ` the gap above the last scanned row, possibly belonging to another scope, is locked; under `READ COMMITTED` it is not. Writers that do not use locking reads are not serialized. Locks taken by earlier reads of a repeated call are kept until the transaction ends, so a call that repeats holds locks on every tail it read (the earlier tail and the newer one), which slightly widens the lock footprint and the contention and deadlock surface compared with a single read.
  * `public function rowExistsInScope(\PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue, int $id): bool`
    * Returns `false` for `id <= 0`.
    * Returns `false` when the row is absent from the requested scope.
    * Treats soft-deleted rows as absent when soft-delete filtering is configured.
    * May throw `InvalidOrderingOperationException` for inconsistent scope usage.
    * May propagate external PDO/database throwables unchanged.
  * `public function moveWithinScope(\PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue, int $id, int $newOrder, ?string $updatedAtValue = null): bool`
    * Rejects inconsistent scope usage (`InvalidOrderingOperationException`).
    * Throws `InvalidOrderingOperationException` for `id <= 0`.
    * Throws `InvalidOrderingOperationException` for `newOrder <= 0`.
    * Owns a transaction when PDO has no active transaction.
    * Participates in an active caller-owned PDO transaction without beginning, committing, or rolling it back.
    * Locks the applicable active scope with `SELECT ... FOR UPDATE` and takes the clamp maximum from the order values of the rows that same locking read returns (0 for an empty scope), not from a separate non-locking read. Under `REPEATABLE READ` this means that, inside a caller-owned transaction whose consistent-read snapshot is older than another connection's commit, the clamp follows the latest committed active rows the locking read observed. Scope and soft-delete filtering of that read are the same as for the movement itself. This is not a guarantee that rows inserted later are serialized (no all-isolation phantom-free guarantee; for example InnoDB generally takes no gap locks under `READ COMMITTED`), and an empty scope has no row to lock. Unreleased; not part of the stable `v1.4.0`.
    * A configured nullable scope uses `scopeColumn IS NULL` when `$scopeValue` is null.
    * Reads the target order inside the transaction.
    * Returns `false` if the target is missing.
    * Clamps above-maximum order to the maximum read by the scope lock (see above); gaps are not normalized, so the maximum is the highest active order value.
    * Returns `true` for an already satisfied/clamped no-op.
    * Shifts only the affected range.
    * When `updatedAtColumn` is configured, `$updatedAtValue` is written with the target order in the same update and transaction.
    * Does not globally normalize pre-existing gaps.
    * Returns `false` and rolls back if the final target update reports no affected row.
    * Rolls back an owned transaction when a throwable occurs after transaction startup.
    * Rethrows the same original throwable.
    * Does not universally wrap PDO failures as package exceptions.

### `Maatify\Persistence\Pdo\Transaction\TransactionRunnerInterface`
* **Status**: `interface`
* **Public Method**:
  ```php
  public function run(callable $callback): mixed
  ```
* **Contract**:
  * The callback receives no arguments and its return value is returned unchanged.
  * When no transaction is active, the implementation starts a transaction,
    commits after successful callback completion, and rolls back on failure if
    the transaction is still active.
  * When a transaction is already active, the implementation participates in it
    and does not begin, commit, or roll it back.
  * The original callback `Throwable` is propagated.

### `Maatify\Persistence\Pdo\Transaction\PdoTransactionRunner`
* **Status**: `final readonly class`
* **Implements**: `TransactionRunnerInterface`
* **Constructor**:
  ```php
  public function __construct(\PDO $pdo)
  ```
  * The PDO connection is an implementation dependency and is not exposed by
    the shared transaction contract.
* **Transaction Boundary**: It owns only transactions that it starts. The
  caller owns an already-active transaction.
* **Composition Requirement**: Atomic composition requires every participant to
  use the same PDO connection.

### `Maatify\Persistence\Pdo\Transaction\SavepointTransactionRunnerInterface`
* **Status**: `interface`
* **Extends**: `TransactionRunnerInterface`
* **Public Methods**:
  * Inherits `run(callable $callback): mixed`
* **Contract**: Provides stronger operation-local savepoint semantics when an outer transaction exists, without altering the inherited signature.

### `Maatify\Persistence\Pdo\Transaction\PdoSavepointTransactionRunner`
* **Status**: `final readonly class`
* **Implements**: `SavepointTransactionRunnerInterface`
* **Constructor**:
  ```php
  public function __construct(\PDO $pdo)
  ```
* **Transaction Boundary**:
  * **No active transaction**: Preserves normal owned-transaction behavior.
  * **Active outer transaction**: Uses an operation-local savepoint. The caller-owned outer transaction is never committed or fully rolled back by the runner.
* **Composition Requirement**: Same PDO connection is required. Nested and repeated usage is completely supported.
* **Deprecation status**: Neither this runner nor `PdoTransactionRunner` is deprecated. Neither replaces the other.

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

### `Maatify\Persistence\Exception\TransactionExecutionException`
* **Status**: `final class`
* **Extends**: `Maatify\Exceptions\Exception\System\SystemMaatifyException`
* **Implements**: `Maatify\Persistence\Exception\PersistenceException`
* **Trigger**: Package-owned non-throwing execution failures for transaction control statements (e.g., when PDO `exec()` returns `false` instead of throwing an exception during savepoint orchestration).

## Usage Guide and Examples

For a practical, runnable walkthrough of position allocation (transaction
setup, `INSERT`, commit or rollback, `REPEATABLE READ` snapshots, empty scopes,
locks, and retry responsibility), see the
[How-to-Use guide](docs/guides/NEXT_POSITION_ALLOCATION_HOW_TO_USE.md) and the
standalone scripts in [`examples/next-position`](examples/next-position). The
guide illustrates this contract; this Reference remains normative.
`getNextPositionForUpdate()` is Unreleased and not part of the stable `v1.4.0`.

For moving a row with `moveWithinScope()`, including inside a caller-owned
`REPEATABLE READ` transaction whose snapshot is older than another connection's
commit, see the
[Move-within-scope How-to-Use guide](docs/guides/MOVE_WITHIN_SCOPE_HOW_TO_USE.md)
and the runnable script
[`examples/move-within-scope/01_move_after_older_snapshot.php`](examples/move-within-scope/01_move_after_older_snapshot.php).
It is a separate path from position allocation: `moveWithinScope()` does not call
`getNextPositionForUpdate()`, and the `examples/next-position` scripts do not
cover movement. The movement fix is Unreleased and not part of the stable `v1.4.0`.

## SQL and Trust Boundaries

* **Identifiers**: SQL identifiers (table, columns) are validated configuration, not user input. They cannot be PDO-bound. Supported formats are standard identifier naming rules and `schema.table`.
* **Values**: All runtime values (ids, scope values) use prepared statements and PDO parameter binding.
* **No Host Assumptions**: No host schema assumptions or ORM dependencies.

## Transaction Composition

* `PdoTransactionRunner` owns `beginTransaction()`, `commit()`, and
  `rollBack()` only when it starts the transaction.
* When the supplied PDO already has an active transaction, the runner and
  `moveWithinScope()` participate in it without committing or rolling it back.
* The caller owns the final commit or rollback for an active outer transaction.
* Atomic composition is supported only when ordinary PDO mutations and all
  package participants use the same PDO connection.
* `getNextPosition()` does not start a transaction or lock its scope. The
  caller owns the transaction and the lock that serializes concurrent
  position allocation; under `REPEATABLE READ` that lock must be taken before
  the transaction's first non-locking read, otherwise the plain read can
  return a stale maximum. Use `getNextPositionForUpdate()` to allocate a
  position inside a caller-owned transaction.
* `getNextPositionForUpdate()` requires an active PDO transaction, never starts
  one, and reads the scope's last active row with `SELECT ... FOR UPDATE`, so it
  sees the latest committed maximum and serializes lock-using allocation and
  reordering on a non-empty scope until the caller commits or rolls back.
  Serializing the first insert into an empty scope remains the caller's
  responsibility.
* `moveWithinScope()` locks the applicable active ordering scope with
  `SELECT ... FOR UPDATE` inside the transaction it owns or joins, and derives
  its clamp maximum from the rows that locking read returns. In a caller-owned
  `REPEATABLE READ` transaction with an older snapshot the clamp therefore
  follows the latest committed rows the locking read observed, not the
  snapshot. The locks are held until the owning transaction ends; it never
  commits or rolls back a caller-owned transaction. This is not a guarantee
  about later concurrent inserts in any isolation level.

## Transaction Savepoint Orchestration Boundaries

For the detailed behavioral contract and runner selection guidance across both composed transactions and savepoint orchestration, see the [PDO Transaction Architecture](docs/architecture/PDO_TRANSACTION_ARCHITECTURE.md).

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
| `InvalidOrderingOperationException` | `ValidationMaatifyException` | `ErrorCodeEnum::INVALID_ARGUMENT` | default | Invalid runtime id, new order, or scope usage, or an unmet operation precondition (`getNextPositionForUpdate()` without an active PDO transaction). |
| `OrderingTransactionException` | `UnsupportedMaatifyException` | `ErrorCodeEnum::UNSUPPORTED_OPERATION` | `defaultIsSafe(): false` | Deprecated compatibility exception; no longer thrown by `moveWithinScope()` for active caller-owned transactions. |
| `InvalidPaginationConfigurationException` | `SystemMaatifyException` | `ErrorCodeEnum::MAATIFY_ERROR` | default | Invalid per-page bounds, whitelist errors. |
| `InvalidPaginationQueryException` | `SystemMaatifyException` | `ErrorCodeEnum::MAATIFY_ERROR` | default | Missing/empty SQL, semicolons, reserved parameters. |
| `PaginationExecutionException` | `SystemMaatifyException` | `ErrorCodeEnum::MAATIFY_ERROR` | default | Package-owned execution and result-contract failures. |
| `TransactionExecutionException` | `SystemMaatifyException` | `ErrorCodeEnum::MAATIFY_ERROR` | default | Package-owned non-throwing execution failures for transaction control statements (e.g. savepoint execution). |
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
  * Unmet operation precondition: `getNextPositionForUpdate()` called without an active PDO transaction

### `Maatify\Persistence\Exception\OrderingTransactionException`
* **Status**: `final class`, `@deprecated`
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
* **Compatibility**: The class remains public and autoloadable for `1.x` consumers.
* **Transaction Flow**: `moveWithinScope()` now participates in an active caller-owned PDO transaction and no longer throws this exception for that condition.

## Integration Requirements

* **Real MySQL Requirement**: Required for integration tests. No SQLite fallback.
* **Environment Variables**:
  * `PERSISTENCE_TEST_MYSQL_DSN`
  * `PERSISTENCE_TEST_MYSQL_USER`
  * `PERSISTENCE_TEST_MYSQL_PASSWORD`
* **Test Database Isolation**: Assumes isolated test tables and requires local package privileges (trigger/table cleanup). Tests include trigger failure injection.
* **Current CI MySQL Baseline**: MySQL 8.4.10. MariaDB is not independently verified by the current CI matrix.
* **Consumer Verification Harness**: `composer test:consumer` installs the
  package into a separate non-symlinked Composer root and verifies a public
  ordering, savepoint, and pagination workflow against real MySQL twice from
  clean consumer/database state.

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
* No transaction participation across different PDO connections
