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
* **Release status**: `composer require maatify/persistence` installs the latest
  tagged release, `v1.4.0`. Items marked *Unreleased* in this Reference exist on
  `main` only; no later version number has been assigned. Unreleased items:
  `ScopedOrderingManager::getNextPositionForUpdate()`, the `moveWithinScope()`
  stale-snapshot clamp fix (Issue #33), `PdoDeadlockRetryTransactionRunner`,
  `PdoDeadlockRetrySavepointTransactionRunner`,
  `InvalidTransactionConfigurationException`, and the deprecation of
  `PdoTransactionRunner` / `PdoSavepointTransactionRunner`. The other public
  classes and methods listed in this Reference are present in `v1.4.0`; this
  states API availability only. Behavior, fixes and examples described here
  reflect `main` and may differ from the tagged release (for example, the
  `moveWithinScope()` clamp fix above), so do not assume that every behavior in
  this document is in `v1.4.0`.

## Persistence and Schema Ownership

The package owns the reusable PDO ordering, transaction, savepoint, and
pagination behavior, but it does not own a persistent business entity or a
production table. Consumers provide their own trusted table and column
identifiers, SQL, scopes, and mapping. The package does not create migrations,
foreign keys, or joins to Host tables. The persistence profile is
MySQL/MariaDB-compatible SQL through direct PDO. Repository integration and
Consumer verification run against real MySQL, with MySQL 8.4.10 as the current
CI baseline; MariaDB is not independently verified by the current CI matrix.
MariaDB 11.8 enables snapshot isolation by default (`innodb_snapshot_isolation`),
and with that setting `ON` the stale-snapshot ordering calls inside a caller-owned
transaction raise error `1020`, which ends the caller's whole transaction. The
guarantee boundaries and the consumer options are in
[Ordering Guarantees and MariaDB `innodb_snapshot_isolation`](#ordering-guarantees-and-mariadb-innodb_snapshot_isolation)
(evidence: [Issue #47 study](docs/studies/issue-47-mariadb-snapshot/REPORT.md)).
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
  * `public function getNextPositionForUpdate(\PDO $pdo, ScopedOrderingConfig $config, int|string|null $scopeValue = null): int` (**Unreleased**, not part of the stable `v1.4.0`)
    * Returns `MAX(order) + 1` from the latest committed state (`1` for an empty applicable scope); soft-deleted rows are ignored when soft-delete filtering is configured.
    * Requires an active PDO transaction on the connection; throws `InvalidOrderingOperationException` when none is active. Never begins, commits, or rolls back a transaction.
    * Performs `SELECT order ... ORDER BY order DESC, id DESC LIMIT 1 FOR UPDATE` (configured order and id columns); the lock is held until the caller ends the transaction. The locking read is repeated until two consecutive reads return the same order value, so every call issues at least two locking reads (also for an empty scope), and a call that waited for the lock on the previous tail follows a higher tail committed meanwhile (observable under `READ COMMITTED`). The repeat continues only while the tail keeps changing, and each repeated read can itself wait for a lock or fail with a lock wait timeout or deadlock.
    * May throw `InvalidOrderingOperationException` for inconsistent scope usage.
    * External PDO/database throwables, including lock wait timeout and deadlock errors, may propagate unchanged; retrying is the caller's responsibility. Under `REPEATABLE READ`, a call queued behind a transaction that then inserts above the locked row typically fails with a deadlock instead of resuming.
    * Guarantee: on a non-empty scope, callers that also use locking reads (this method, `moveWithinScope()`) are serialized on the scope's last active row.
    * Engine condition: the "latest committed state inside an older `REPEATABLE READ` snapshot" result holds on MySQL 8.0/8.4 and on MariaDB with `innodb_snapshot_isolation=OFF` (tested). On MariaDB with the setting `ON` (the 11.8 default), a call that visits a row changed after the snapshot throws the engine's `PDOException` with driver code `1020`, unchanged, and the server has already rolled back the caller's **whole** transaction; the caller must not catch it and continue, and must restart the unit of work. See [Ordering Guarantees and MariaDB `innodb_snapshot_isolation`](#ordering-guarantees-and-mariadb-innodb_snapshot_isolation).
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
    * **Incompatible with a `UNIQUE (scope, order)` index**: see [Ordering and Unique Indexes](#ordering-and-unique-indexes).
    * Locks the applicable active scope with `SELECT ... FOR UPDATE` and takes the clamp maximum from the order values of the rows that same locking read returns (0 for an empty scope), not from a separate non-locking read. Under `REPEATABLE READ` this means that, inside a caller-owned transaction whose consistent-read snapshot is older than another connection's commit, the clamp follows the latest committed active rows the locking read observed. Scope and soft-delete filtering of that read are the same as for the movement itself. This is not a guarantee that rows inserted later are serialized (no all-isolation phantom-free guarantee; for example InnoDB generally takes no gap locks under `READ COMMITTED`), and an empty scope has no row to lock. Unreleased; not part of the stable `v1.4.0`.
    * A configured nullable scope uses `scopeColumn IS NULL` when `$scopeValue` is null.
    * Reads the target order inside the transaction.
    * Returns `false` if the target is missing.
    * Clamps above-maximum order to the maximum read by the scope lock (see above); gaps are not normalized, so the maximum is the highest active order value.
    * Returns `true` for an already satisfied/clamped no-op.
    * Engine condition (also in the stable `v1.4.0`, for the caller-owned case): in a caller-owned `REPEATABLE READ` transaction with an older snapshot, the scope lock needs a current read. On MariaDB with `innodb_snapshot_isolation=ON` it throws the engine's `PDOException` with driver code `1020`, unchanged, after the server has rolled back the caller's **whole** transaction; do not catch it and continue. A call that owns its transaction (none active) is unaffected. See [Ordering Guarantees and MariaDB `innodb_snapshot_isolation`](#ordering-guarantees-and-mariadb-innodb_snapshot_isolation).
    * Shifts only the affected range.
    * When `updatedAtColumn` is configured, `$updatedAtValue` is written with the target order in the same update and transaction.
    * Does not globally normalize pre-existing gaps.
    * Returns `false` if the final target update reports no affected row. In a transaction the method owns, it rolls that transaction back first; in a caller-owned transaction it does **not** roll back, and rows already shifted stay in the caller's transaction (see [Rollback Ownership](#rollback-ownership)).
    * Rolls back an owned transaction when a throwable occurs after transaction startup. In a caller-owned transaction a throwable leaves the already executed shifts in place; the caller decides on a full rollback. There is no savepoint and no savepoint-rollback guarantee in this method.
    * Rethrows the same original throwable.
    * Does not universally wrap PDO failures as package exceptions.

### `Maatify\Persistence\Pdo\Transaction\TransactionRunnerInterface`
* **Status**: `interface` (released in `v1.3.0`)
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
  * When a transaction the runner owns fails, the whole transaction is rolled
    back. When the transaction is caller-owned, nothing is rolled back by the
    runner (see [Rollback Ownership](#rollback-ownership)).
  * The interface itself is unchanged and not deprecated. An implementation may
    replay an owned transaction only when explicitly configured to
    (`PdoDeadlockRetryTransactionRunner`, `PdoDeadlockRetrySavepointTransactionRunner`);
    with their default configuration the callback runs once.

### `Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetryTransactionRunner`
* **Status**: `final readonly class`; Unreleased, not part of the stable `v1.4.0`
* **Implements**: `TransactionRunnerInterface`
* **Replaces**: `PdoTransactionRunner` (deprecated, see the
  [compatibility inventory](#compatibility-deprecated-transaction-runners))
* **Constructor**:
  ```php
  public function __construct(\PDO $pdo, int $maxDeadlockRetries = 0)
  ```
  * The PDO connection is an implementation dependency and is not exposed by
    the shared transaction contract.
  * `$maxDeadlockRetries`: number of replays of an owned transaction after a
    deadlock; allowed range `0` to `3`; default `0` (no replay). Any other value
    throws `InvalidTransactionConfigurationException` from the constructor.
* **Public Method**: `run(callable $callback): mixed` (from `TransactionRunnerInterface`).
* **Transaction Boundary**:
  * **No active transaction (owned)**: begins a transaction, runs the callback,
    commits, and returns the callback result. If the callback or `COMMIT`
    throws, it rolls back if the transaction is still active and rethrows the
    original `Throwable`. A rollback failure never replaces it.
  * **Non-throwing control failures (fail-closed)**: if `beginTransaction()`
    returns `false`, the callback is not run and `TransactionExecutionException`
    is thrown. If `commit()` returns `false`, the result is not returned (it is
    never reported as committed), a best-effort rollback is attempted, and
    `TransactionExecutionException` is thrown; it is not replayed. This is the
    only difference from `PdoTransactionRunner` with the default `0`, which
    ignores those return values.
  * **Active transaction (caller-owned)**: runs the callback inside it without
    `BEGIN`, `COMMIT`, rollback, or replay, whatever `$maxDeadlockRetries` is.
* **Deadlock replay (owned transactions only)**: the whole transaction is
  replayed from a fresh `BEGIN` only when all of the following hold:
  * the callback (not `BEGIN` or `COMMIT`) threw a `PDOException` whose
    `errorInfo` is SQLSTATE `40001` with driver code `1213`;
  * a replay is left (at most `$maxDeadlockRetries`);
  * the driver still reports the transaction as active when the deadlock
    arrives. `pdo_mysql` derives `inTransaction()` from the last successful
    server reply, so for a deadlock victim on this connection it still reports
    the runner's transaction. If it already reports none, the transaction ended
    before the deadlock (for example the callback committed or rolled it back),
    the end cannot be attributed to the deadlock, and nothing is replayed;
  * a no-op statement (`DO 0`) then succeeds and the connection reports no
    active transaction any more (InnoDB rolls back the whole transaction of a
    deadlock victim).

  Delays before replay 1, 2 and 3: none, a uniform random 0-10 ms, a uniform
  random 0-20 ms. No transaction and no lock is held while waiting.
* **Never replayed**: `1205` lock wait timeouts and every other error or
  non-`PDOException` `Throwable`; `1213` with another SQLSTATE or `40001` with
  another driver code; `BEGIN` and `COMMIT` failures, thrown or returned as
  `false` (including a deadlock reported by `COMMIT`); a deadlock that arrives
  after the runner's transaction already ended (for example the callback
  committed it and another connection raised the `1213`); a deadlock after
  which the probe fails or the transaction is still active (for example a
  `1213` raised by another connection while the runner's transaction is
  open); any caller-owned transaction.
* **Failure Precedence**: after the last attempt the exact final
  `PDOException` is rethrown (not wrapped). If a replay's `BEGIN` fails, that
  failure propagates and no further attempt runs. A failed probe never replaces
  the deadlock.
* **Callback replay contract**: with `$maxDeadlockRetries > 0` the whole
  callback may run up to `$maxDeadlockRetries + 1` times. Only its work on this
  PDO connection inside the runner-owned transaction is rolled back between
  attempts; e-mails, HTTP calls, queue messages, files, other connections and
  in-memory state are not, and would be repeated. Keep such effects outside the
  callback (after `run()` returns) or make them idempotent. The callback must not
  end or replace the runner-owned transaction, neither explicitly (`commit()`,
  `rollBack()`, `beginTransaction()`, or SQL such as `START TRANSACTION`,
  `COMMIT`, `ROLLBACK`) nor implicitly (DDL and other implicit-commit
  statements, or session/autocommit changes).
* **Callback transaction-ownership precondition (Owner-accepted limit)**: keeping
  the runner-owned transaction intact is a mandatory precondition of replay.
  Callbacks that end or replace it are outside the supported contract, and the
  runner gives no transaction-identity guarantee for them: neither the
  driver-reported state nor the `DO 0` probe proves transaction identity. A
  transaction that already ended while the connection is outside a transaction
  when the deadlock arrives is not replayed; but a callback that ends the
  runner-owned transaction **and** leaves the same connection inside another
  transaction when a deadlock hits it (an explicit new `BEGIN`, or an implicit
  commit such as DDL followed by an implicit transaction under
  `autocommit = 0`) looks like a deadlock victim, and its already committed
  work would be replayed (cases M3 and M4 in ADR 0004). This is unsupported
  usage and is not automatically prevented. Accepted by the Owner
  ([approval](https://github.com/Maatify/persistence/issues/32#issuecomment-6296393312), see
  [ADR 0004](docs/adr/0004-deadlock-retry-transaction-runner-generation.md#accepted-limitation-callback-transaction-ownership-precondition)).
* **Composition Requirement**: Atomic composition requires every participant to
  use the same PDO connection.
* **Measured scope of the value `3`**: the Issue #32 study measured three
  retries with this delay schedule in one synchronized 8-connection workload
  ([report](docs/studies/issue-32-retry-comparison/ISSUE-42-REPORT.md)); it is
  not a guarantee for other workloads, and it is not the default.

### `Maatify\Persistence\Pdo\Transaction\SavepointTransactionRunnerInterface`
* **Status**: `interface`
* **Extends**: `TransactionRunnerInterface`
* **Public Methods**:
  * Inherits `run(callable $callback): mixed`
* **Contract**: Provides stronger operation-local savepoint semantics when an outer transaction exists, without altering the inherited signature.

### `Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner`
* **Status**: `final readonly class`; Unreleased, not part of the stable `v1.4.0`
* **Implements**: `SavepointTransactionRunnerInterface`
* **Replaces**: `PdoSavepointTransactionRunner` (deprecated, see the
  [compatibility inventory](#compatibility-deprecated-transaction-runners))
* **Constructor**:
  ```php
  public function __construct(\PDO $pdo, int $maxDeadlockRetries = 0)
  ```
  * Same range, default and validation as `PdoDeadlockRetryTransactionRunner`.
* **Transaction Boundary**:
  * **No active transaction**: exactly the owned-transaction behavior of
    `PdoDeadlockRetryTransactionRunner`, including the optional deadlock replay,
    its exclusions and its callback replay contract. No savepoint SQL is issued.
  * **Active outer transaction**: uses an operation-local savepoint
    (`maatify_persistence_sp_<32 lowercase hex>`), releases it on success, and
    on failure attempts `ROLLBACK TO SAVEPOINT` (then `RELEASE`) while the outer
    transaction is still active. The caller-owned outer transaction is never
    committed or fully rolled back by the runner. **The savepoint path never
    replays anything**, including after a deadlock; a deadlock has already
    rolled back the whole caller-owned transaction, and the caller decides.
    The savepoint isolates statement-level failures only; see
    [Deadlock `1213` and Caller-Owned Transactions](#deadlock-1213-and-caller-owned-transactions).
* **Failure Precedence**: unchanged from the savepoint contract in the
  [PDO Transaction Architecture](docs/architecture/PDO_TRANSACTION_ARCHITECTURE.md):
  the callback `Throwable` (or the `RELEASE` failure of a successful callback)
  always wins over cleanup failures; PDO `false` for a primary control
  statement is `TransactionExecutionException`.
* **Composition Requirement**: Same PDO connection is required. Nested and
  repeated usage is supported; nested inside an owning runner, a deadlock in the
  inner savepoint replays the whole outer owned transaction (when configured),
  never only the savepoint.

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
* **Trigger**: Package-owned non-throwing execution failures for transaction control statements (e.g., when PDO `exec()` returns `false` instead of throwing an exception during savepoint orchestration, or when `beginTransaction()` / `commit()` returns `false` in an owned transaction of the deadlock-retry runners).

### `Maatify\Persistence\Exception\InvalidTransactionConfigurationException`
* **Status**: `final class`; Unreleased, not part of the stable `v1.4.0`
* **Extends**: `Maatify\Exceptions\Exception\System\SystemMaatifyException`
* **Implements**: `Maatify\Persistence\Exception\PersistenceException`
* **Error Code**: `ErrorCodeEnum::MAATIFY_ERROR`
* **Trigger**: A deadlock-retry runner constructed with `$maxDeadlockRetries`
  outside `0` to `3`. A configuration/programming mistake, not user-input validation.

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

For the optional deadlock replay (Unreleased), the scripts in
[`examples/deadlock-retry`](examples/deadlock-retry) produce a real InnoDB
deadlock with a second process: an owned transaction replayed and committed once
(with its external side effect after commit, once), and a caller-owned
transaction that is never replayed. The deadlock-retry runners are Unreleased
and not part of the stable `v1.4.0`.

## SQL and Trust Boundaries

* **Identifiers**: SQL identifiers (table, columns) are validated configuration, not user input. They cannot be PDO-bound. Supported formats are standard identifier naming rules and `schema.table`.
* **Values**: All runtime values (ids, scope values) use prepared statements and PDO parameter binding.
* **No Host Assumptions**: No host schema assumptions or ORM dependencies.

## Transaction Composition

* `PdoTransactionRunner` (released) and, Unreleased,
  `PdoDeadlockRetryTransactionRunner` (and the owned path of
  `PdoDeadlockRetrySavepointTransactionRunner`) owns `beginTransaction()`,
  `commit()`, and `rollBack()` only when it starts the transaction, and may
  replay only that transaction after a deadlock when configured to (the replay is
  Unreleased; `PdoTransactionRunner` never replays).
* When the supplied PDO already has an active transaction, the runner and
  `moveWithinScope()` participate in it without committing or rolling it back.
* The caller owns the final commit or rollback for an active outer transaction.
  A failure inside a caller-owned transaction does not undo the participant's
  earlier changes automatically (see [Rollback Ownership](#rollback-ownership)).
* Atomic composition is supported only when ordinary PDO mutations and all
  package participants use the same PDO connection.
* `getNextPosition()` does not start a transaction or lock its scope. The
  caller owns the transaction and the lock that serializes concurrent
  position allocation; under `REPEATABLE READ` that lock must be taken before
  the transaction's first non-locking read, otherwise the plain read can
  return a stale maximum. Use `getNextPositionForUpdate()` to allocate a
  position inside a caller-owned transaction.
* `getNextPositionForUpdate()` (Unreleased) requires an active PDO transaction, never starts
  one, and reads the scope's last active row with `SELECT ... FOR UPDATE`, so it
  sees the latest committed maximum and serializes lock-using allocation and
  reordering on a non-empty scope until the caller commits or rolls back.
  Serializing the first insert into an empty scope remains the caller's
  responsibility. That current-read guarantee is engine-conditional: on MariaDB
  with `innodb_snapshot_isolation=ON` it raises `1020` and ends the whole
  transaction (see [Ordering Guarantees and MariaDB `innodb_snapshot_isolation`](#ordering-guarantees-and-mariadb-innodb_snapshot_isolation)).
* `moveWithinScope()` locks the applicable active ordering scope with
  `SELECT ... FOR UPDATE` inside the transaction it owns or joins, and derives
  its clamp maximum from the rows that locking read returns. In a caller-owned
  `REPEATABLE READ` transaction with an older snapshot the clamp therefore
  follows the latest committed rows the locking read observed, not the
  snapshot. The locks are held until the owning transaction ends; it never
  commits or rolls back a caller-owned transaction. This is not a guarantee
  about later concurrent inserts in any isolation level. On MariaDB with
  `innodb_snapshot_isolation=ON`, that locking read in a caller-owned
  transaction with an older snapshot raises `1020` and ends the whole
  transaction, in the stable `v1.4.0` as well (see
  [Ordering Guarantees and MariaDB `innodb_snapshot_isolation`](#ordering-guarantees-and-mariadb-innodb_snapshot_isolation)).

### Rollback Ownership

| Situation | Who rolls back | What happens to partial work |
|---|---|---|
| No transaction active; a runner or `moveWithinScope()` begins one | The runner / `moveWithinScope()` | The whole owned transaction is rolled back (`moveWithinScope()` also rolls back before returning `false`). Nothing partial remains. |
| Transaction already active (caller-owned); a runner or `moveWithinScope()` participates | The caller | Not rolled back by the package. Changes already executed in the caller's transaction (for `moveWithinScope()`: rows already shifted) remain until the caller rolls back, and a caller `commit()` makes them permanent. |
| Caller-owned transaction + savepoint runner (`PdoSavepointTransactionRunner`; Unreleased: `PdoDeadlockRetrySavepointTransactionRunner`) | The runner, to its own savepoint only | Statement-level failures are rolled back to the operation savepoint; the outer transaction stays active and the caller still owns the final outcome. Not applicable to `1213`. |

`moveWithinScope()` creates no savepoint. Wrapping it in a savepoint runner is
possible for statement-level errors but is the caller's choice and not part of
the ordering API contract; the package gives no savepoint-rollback guarantee for
ordering calls.

### Deadlock `1213` and Caller-Owned Transactions

A deadlock (`PDOException`, SQLSTATE `40001`, driver code `1213`) is fatal to an
externally owned MySQL/MariaDB transaction: InnoDB has already rolled back the
**whole** transaction, including the caller's work before the package call, and
a savepoint is gone with it (a later `ROLLBACK TO SAVEPOINT` fails). A savepoint
runner therefore does not cover `1213`.

* The caller must **not continue** in that transaction. `pdo_mysql` may keep
  reporting `inTransaction() === true` until the next successful server reply,
  so the flag is not proof that the work survived. Statements issued afterwards
  may run in autocommit mode and be committed immediately; under
  `autocommit = 0` a later `commit()` can silently make only the post-deadlock
  statements permanent.
* Neither the package nor a `DO 0` statement prevents caller code from writing
  after the deadlock. The Unreleased runners issue `DO 0` only to decide whether a
  failure of a transaction **they own** was a deadlock victim; it is not a
  protection for caller-owned transactions.
* Handle `1213` at the level that owns the whole transaction: rethrow it, and
  re-run the entire unit of work from a fresh `BEGIN` when that is safe
  (idempotent, no un-rollbackable side effects). A retry must wrap the whole
  runner-owned transaction (Unreleased: `maxDeadlockRetries` greater than `0`),
  never a savepoint or a single operation inside a surviving-looking outer
  transaction.
* Catch only expected error codes (`1062`, `1205`, package exceptions) around a
  savepoint runner; do not catch `Throwable` generically and continue.

Evidence is limited to MySQL 8.4.10 and (for the probe behavior) MariaDB 11.8.9:
[DG-03 assessment](https://github.com/Maatify/persistence/blob/3b5b71f2868cd6c1451691f036473a870d135c2b/docs/studies/full-fresh-review/DG03-ASSESSMENT.md) and its
[follow-up](https://github.com/Maatify/persistence/blob/3b5b71f2868cd6c1451691f036473a870d135c2b/docs/studies/full-fresh-review/ADDENDUM-2-F45-RESPONSE.md) on PR #45.

### Ordering Guarantees and MariaDB `innodb_snapshot_isolation`

Evidence: [Issue #47 study](docs/studies/issue-47-mariadb-snapshot/REPORT.md)
(MySQL 8.0.46, 8.4.10; MariaDB 10.11.18, 11.4.7, 11.8.9; `REPEATABLE READ`;
PHP 8.4.4 with `pdo_mysql`). Anything outside those cells is **untested**: other
MariaDB versions (including which version introduced the variable or flipped its
default), `SERIALIZABLE`, MySQL 5.7/9.x, and proxy or cluster layers.

**What is guaranteed, and where.** Inside a caller-owned transaction whose
`REPEATABLE READ` snapshot is older than another connection's commit,
`getNextPositionForUpdate()` and the `moveWithinScope()` clamp (Issue #33) rely
on a *current read*: a locking statement that acts on the latest committed rows
instead of the snapshot. That guarantee holds only where the engine performs
current reads for locking statements:

| Engine / setting | Stale-snapshot ordering calls inside a caller-owned transaction |
|---|---|
| MySQL 8.0 / 8.4 (variable does not exist) | Supported; the guarantee above holds (tested) |
| MariaDB with `innodb_snapshot_isolation=OFF` (the default on 10.11.18 and 11.4.7; 11.8.9 with the setting turned off) | Same results as MySQL (tested) |
| MariaDB with `innodb_snapshot_isolation=ON` (the default on 11.8.9; also 10.11.18 and 11.4.7 once enabled) | **Not available.** The call raises the engine's `PDOException`, driver code `1020` (SQLSTATE `HY000`), unchanged |

The setting decides, not the server version. The error appears only when the
locking statement visits a row that changed after the transaction's snapshot,
which is exactly the case where the Issue #33 behavior matters; with no
concurrent change, or a change confined to another scope, nothing fails. The
package does not read, set, or override `innodb_snapshot_isolation`.

**What is not affected.** A *package-owned* `moveWithinScope()` (called with no
active transaction) begins its own transaction, so its locking read is the first
statement and no older snapshot exists. A caller that takes its serialization
lock before the transaction's first plain read creates no stale snapshot either.
The shipped `v1.4.0` `moveWithinScope()` is affected in the caller-owned case
(its scope lock is the same locking read); `getNextPositionForUpdate()` and the
Issue #33 clamp are Unreleased.

**`1020` ends the caller's whole transaction.** The server rolls back the
**entire** transaction, including the caller's writes made before the failing
call, and releases its locks. `PDO::inTransaction()` can still report `true`.
What happens to the statements issued after the error depends on the server
version: on MariaDB 10.11.18 and 11.8.9 they run in autocommit and survive a
later `PDO::rollBack()`; on 11.4.7 a new implicit transaction is open, so a
later `commit()` would persist only post-error work. In both shapes atomicity is
silently lost if the caller catches the exception and continues.

* **Never catch `1020` and continue** in that transaction. Treat it like `1213`
  (see [Deadlock `1213` and Caller-Owned Transactions](#deadlock-1213-and-caller-owned-transactions)):
  propagate it to the code that owns the whole transaction, which restarts the
  entire unit of work from a fresh `BEGIN`.
* The package propagates the original `PDOException` unchanged, also through
  `PdoSavepointTransactionRunner` (its cleanup `ROLLBACK TO SAVEPOINT` cannot
  succeed and is swallowed, the original exception is rethrown). A
  runner-owned transaction is closed and the exception rethrown.
  `PdoDeadlockRetryTransactionRunner` does **not** replay `1020` (one attempt).
  In every `1020` trace recorded by the study, the failing call returned no
  position and made no clamp decision. That describes exception propagation on
  the tested traces; it is **not** transaction safety and not a guarantee for
  untested engines, versions or schedules.
* A savepoint does not help: the savepoint is gone with the transaction.
* The package does not retry `1020`. Restarting is possible only for whoever
  owns the transaction; the package cannot restart a caller-owned one.

**Consumer options** (the remedy belongs to the consumer; ordered by preference
for the allocating transaction, with the limits of each):

1. **Serialize first.** Take the caller's serialization lock (for example a lock
   on a parent row that every competing writer takes) before the transaction's
   first plain read. No stale snapshot exists, so no `1020`. This needs a
   consumer-owned lock row; the package cannot create one, and an empty scope
   still needs it.
2. **Run the allocating or moving transaction under `READ COMMITTED`**
   (`SET TRANSACTION ISOLATION LEVEL READ COMMITTED` before `BEGIN`). Results
   were correct on MariaDB 11.8.9. Every read in that transaction becomes
   non-repeatable, and MariaDB requires a `ROW` or `MIXED` binlog format for it
   (not tested here).
3. **Set `innodb_snapshot_isolation=OFF`** for the connection (session, global or
   pool initialization), if the consumer accepts the trade. It disables the
   engine's lost-update protection for **all** statements on that connection,
   not only the ordering calls.
4. **Restart on `1020`** (always required, in addition to one of the above when
   the situation can arise): the transaction is already gone, so the owner
   restarts the whole unit of work. This alone does not make a repeated
   stale-snapshot attempt succeed; it only avoids continuing after the failure.

The package deliberately does not apply any of these itself. It does not issue
`SET SESSION innodb_snapshot_isolation=OFF` (it would alter a connection the
caller owns and the variable does not exist on MySQL), does not switch isolation
levels mid-transaction (not possible), does not catch `1020` and retry (no
transaction exists afterwards), does not read the latest state on a second
connection, and does not replay caller-owned work. A MariaDB-only,
statement-scoped override (`SET STATEMENT innodb_snapshot_isolation=OFF FOR …`)
was evaluated and is not provided; it would silently override a host safety
setting and still leave the caller's own later statements engine-dependent.

MariaDB is still not an independent CI verification target (see
[Issue #51](https://github.com/Maatify/persistence/issues/51) for the separate
MariaDB integration-suite gaps); the table above is evidence from the study, not
a supported-matrix claim.

### Ordering and Unique Indexes

`moveWithinScope()` is **not compatible with a `UNIQUE (scope, order)` index**.
It shifts the range between the old and the new position with one
`UPDATE ... SET order = order ± 1` per direction, then writes the target row.
InnoDB evaluates a unique index row by row during an `UPDATE`, so the
intermediate state raises duplicate-key error `1062`; in the study, with
sequential positions only no-op moves passed, and moves failed both in
operation-owned and in caller-owned transactions. This applies to any unique
index that spans the ordering column and scope, including one that keeps
soft-deleted rows.

* For a table whose rows are moved with `moveWithinScope()`, use a non-unique
  `(scope, order)` index and serialize writers with the locking APIs (and, for
  an empty scope, a parent-row lock).
* A `UNIQUE (scope, order)` index remains a valid safety net for allocation
  inserts (`getNextPosition()` / `getNextPositionForUpdate()` followed by an
  `INSERT`). This is a limit of the current method; it is not general advice to
  remove unique indexes.
* **Candidate, not implemented.** The Issue #46 study identified a conditional
  approach (*B3*: park the target at the maximum position over **all** rows read
  with a locking read plus one, ordered shifts, final write, inside a savepoint).
  Its stated conditions are a plain unique index without position-holding
  soft-deleted rows or an "active-only" unique index on a generated column, a
  position below the column type's maximum, acceptance that the temporary
  position is visible to triggers/audit/CDC (and that non-transactional side
  effects are not undone), range-proportional lock cost, and engine-specific
  behavior. This package does **not** implement it: no API exists, nothing is
  promised, and any such work needs its own issue and an owner decision. See the
  [study addendum](https://github.com/Maatify/persistence/blob/3b5b71f2868cd6c1451691f036473a870d135c2b/docs/studies/full-fresh-review/ADDENDUM-2-F45-RESPONSE.md).

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
| `TransactionExecutionException` | `SystemMaatifyException` | `ErrorCodeEnum::MAATIFY_ERROR` | default | Package-owned non-throwing execution failures for transaction control statements (e.g. savepoint execution, or `BEGIN`/`COMMIT` returning `false` in the deadlock-retry runners). |
| `InvalidTransactionConfigurationException` | `SystemMaatifyException` | `ErrorCodeEnum::MAATIFY_ERROR` | default | Deadlock-retry runner constructed with `$maxDeadlockRetries` outside `0` to `3` (Unreleased). |
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

## Compatibility: Deprecated Transaction Runners

Kept for discoverability and contract accuracy of existing `1.x` consumers; new
code uses the replacements above. Both classes remain public, autoloadable,
`final readonly`, and signature- and behavior-compatible through the `1.x`
line; they are not removed before a separately approved future Major release
([ADR 0004](docs/adr/0004-deadlock-retry-transaction-runner-generation.md)).

| Deprecated class (Unreleased deprecation) | Constructor | Behavior | Replacement |
|---|---|---|---|
| `Maatify\Persistence\Pdo\Transaction\PdoTransactionRunner` | `__construct(\PDO $pdo)` | `TransactionRunnerInterface`; owns begin/commit/rollback only when no transaction is active, otherwise participates; never replays | `PdoDeadlockRetryTransactionRunner` (same behavior with the default `0`, except that a `BEGIN`/`COMMIT` returning `false` throws `TransactionExecutionException`) |
| `Maatify\Persistence\Pdo\Transaction\PdoSavepointTransactionRunner` | `__construct(\PDO $pdo)` | `SavepointTransactionRunnerInterface`; owned transaction when none is active, operation-local savepoint otherwise; never replays | `PdoDeadlockRetrySavepointTransactionRunner` (same behavior with the default `0`, except the same fail-closed `BEGIN`/`COMMIT` handling on its owned path) |

`ScopedOrderingManager::moveWithinScope()` still uses `PdoTransactionRunner`
internally for the transaction it owns; its behavior is unchanged and it does
not replay deadlocks.

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
  ordering, savepoint, and pagination workflow, the deprecated runners'
  compatibility, and the documented examples (including real two-process
  deadlocks for the deadlock-retry runners) against real MySQL twice from
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
