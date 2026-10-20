<div align="center">

# Maatify Persistence

![Maatify.dev](https://www.maatify.dev/assets/img/img/maatify_logo_white.svg)

**Package status:**<br>
[![Latest Version](https://img.shields.io/packagist/v/maatify/persistence.svg)](https://packagist.org/packages/maatify/persistence)
[![PHP Version](https://img.shields.io/packagist/php-v/maatify/persistence.svg)](https://packagist.org/packages/maatify/persistence)
[![License: MIT](https://img.shields.io/packagist/l/maatify/persistence.svg)](LICENSE)
[![PHPStan: Level Max](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](phpstan.neon)

**Documentation:**<br>
[![Changelog](https://img.shields.io/badge/Changelog-View-blue.svg)](CHANGELOG.md)
[![Package Reference](https://img.shields.io/badge/Reference-Read-blue.svg)](PERSISTENCE_PACKAGE_REFERENCE.md)
[![Security Policy](https://img.shields.io/badge/Security-Policy-blue.svg)](SECURITY.md)
[![Contributing Guide](https://img.shields.io/badge/Contributing-Guide-blue.svg)](CONTRIBUTING.md)

**Ecosystem and usage:**<br>
[![Monthly Downloads](https://img.shields.io/packagist/dm/maatify/persistence)](https://packagist.org/packages/maatify/persistence)
[![Total Downloads](https://img.shields.io/packagist/dt/maatify/persistence)](https://packagist.org/packages/maatify/persistence)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet)](https://github.com/Maatify)
[![Install](https://img.shields.io/badge/Install-composer%20require%20maatify%2Fpersistence-blue)](https://packagist.org/packages/maatify/persistence)

*Standalone, framework-agnostic PDO utilities for Maatify projects, providing robust scoped and global ordering, composable transaction support, and pagination tools. Designed and verified for MySQL environments.*

> **Note:** PDO Pagination is available starting with v1.1.0.
>
> **Released vs Unreleased:** `composer require maatify/persistence` installs the
> latest tagged release, `v1.4.0`. Everything marked *Unreleased* in this README
> exists on `main` only and is **not** in `v1.4.0`; no later version number has
> been assigned. See [Availability](#-availability-v140-vs-unreleased).

</div>

---

## 🚀 Key Features

* **Global and Scoped Ordering**: Easily manage display order across an entire table or within a specific scope.
* **Composable PDO Transactions**: Owns a transaction when needed and participates in an existing transaction without changing its ownership.
* **Opt-in Deadlock Replay (Unreleased)**: Can replay a transaction the runner itself owns after a MySQL deadlock (`1213`), at most three times with short randomized waits; never inside a caller-owned transaction.
* **Operation-Local Savepoint Orchestration**: Creates operation-local rollback boundaries within caller-owned transactions, allowing an operation that failed with a statement-level error to roll back without fully terminating the outer transaction. A deadlock (`1213`) is not covered: it ends the whole transaction (see [Deadlock `1213` Is Fatal](#-deadlock-1213-is-fatal-to-the-surrounding-transaction)).
* **SQL Identifier Validation**: Ensures table and column configurations are safe and properly quoted.
* **Soft-Delete Filtering**: Optional support for ignoring soft-deleted rows in ordering calculations.
* **Scope Isolation**: Ensures only the affected range within the configured scope is updated.
* **PDO Pagination**: Deterministic offset pagination with strict normalization, bounds checking, and safe whitelist-based sorting.

## ⚙️ Requirements

**Runtime requirements:**
* PHP `>= 8.2`
* `ext-pdo`
* `maatify/exceptions ^1.0`

**Database behavior:**
* The package behavior is designed and verified against MySQL.
* SQL is MySQL/MariaDB-compatible in profile, but MariaDB is not verified by this repository's CI matrix. MariaDB 11.8 defaults to snapshot isolation (`innodb_snapshot_isolation`), which can raise error `1020` in the locking reads of the ordering APIs; this is tracked separately in [Issue #47](https://github.com/Maatify/persistence/issues/47).

## 📦 Installation

```bash
composer require maatify/persistence
```

## 🧭 Availability: `v1.4.0` vs Unreleased

| Capability | Status |
|---|---|
| `ScopedOrderingConfig`, `ScopedOrderingManager::getNextPosition()`, `moveWithinScope()`, `rowExistsInScope()` | Released in `v1.4.0` |
| `PdoTransactionRunner`, `PdoSavepointTransactionRunner`, `TransactionRunnerInterface`, `SavepointTransactionRunnerInterface`, `TransactionExecutionException` | Released in `v1.4.0` |
| PDO Pagination | Released (since `v1.1.0`) |
| `ScopedOrderingManager::getNextPositionForUpdate()` | **Unreleased** |
| `moveWithinScope()` stale-snapshot clamp fix (Issue #33) | **Unreleased** (`v1.4.0` clamps against a separate non-locking maximum) |
| `PdoDeadlockRetryTransactionRunner`, `PdoDeadlockRetrySavepointTransactionRunner`, `InvalidTransactionConfigurationException` | **Unreleased** |
| Deprecation of `PdoTransactionRunner` and `PdoSavepointTransactionRunner` | **Unreleased** (not deprecated in `v1.4.0`) |

The first rows list API *availability* in `v1.4.0` only. A method present in
`v1.4.0` can still behave differently from `main` when a later fix is listed as
Unreleased (for example `moveWithinScope()`'s clamp), and the guides and examples
describe `main`.

*Unreleased* means merged to `main` but not tagged. A class that exists on
`main` cannot be installed with `composer require maatify/persistence` until a
release that contains it is tagged.

## ⚡ Quick Usage

This example uses only APIs that exist in the released `v1.4.0`. It manages the
transaction with plain PDO calls; `v1.4.0` also ships the
`PdoTransactionRunner` / `PdoSavepointTransactionRunner` classes (listed in
[Public Runtime API](#-public-runtime-api)), and the Unreleased replacements are
shown further below.

```php
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

// 1. Configure the ordering behavior for a table
$config = new ScopedOrderingConfig(
    table: 'maa_shipping_rates',
    scopeColumn: 'method_id', // Use null for global ordering
    idColumn: 'id',
    orderColumn: 'display_order',
    deletedAtColumn: 'deleted_at', // Use null if soft-deletes are not used
    // Set nullableScope: true when NULL is a real scope (for example, roots).
    nullableScope: false,
    // Optional: update this column atomically with a successful move.
    updatedAtColumn: null,
);

$ordering = new ScopedOrderingManager();

// 2. Get the next position for a new insert. getNextPosition() is a plain,
// lock-free read: serialize concurrent writers yourself, for example by locking
// a parent row before the transaction's first non-locking read (see
// "Critical Runtime Behavior"). Here the caller owns the transaction.
$pdo->beginTransaction();

try {
    // Serialization precondition: the parent row exists, and EVERY writer that
    // allocates a position in this scope takes this same lock first, before any
    // non-locking read of its transaction. Without an existing, selected row
    // nothing is locked.
    $parentLock = $pdo->prepare('SELECT `id` FROM `maa_shipping_methods` WHERE `id` = :id FOR UPDATE');
    $parentLock->execute(['id' => 2]);

    if ($parentLock->fetchColumn() === false) {
        throw new RuntimeException('Parent row 2 does not exist, so nothing was locked.');
    }

    $nextPosition = $ordering->getNextPosition(
        pdo: $pdo,
        config: $config,
        scopeValue: 2, // Use null for global ordering
    );

    // Insert on the same connection and in the same transaction, then commit
    // (add your table's other columns).
    $pdo->prepare('INSERT INTO `maa_shipping_rates` (`method_id`, `display_order`) VALUES (:method_id, :position)')
        ->execute(['method_id' => 2, 'position' => $nextPosition]);

    $pdo->commit();
} catch (Throwable $throwable) {
    // Best effort: a failing cleanup must never replace the original exception
    // (after a deadlock, 1213, the server has already rolled the transaction
    // back and inTransaction() can be stale, so rollBack() itself may throw).
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable) {
        // Ignored on purpose; the original $throwable is rethrown below.
    }

    throw $throwable;
}
```

This is **error propagation in a transaction you own**, not a retry: the block
attempts a best-effort rollback and rethrows the original exception unchanged, so
a `PDOException` with driver code `1213` reaches your caller intact. The rollback
itself is not guaranteed (for example, a dead connection can make it fail; the
server then discards the transaction when the session ends). Re-running the
whole unit of work after `1213` is a separate, deliberate decision of the code
that owns the transaction (see [Deadlock `1213` Is Fatal](#-deadlock-1213-is-fatal-to-the-surrounding-transaction));
the package's own opt-in replay is Unreleased.

```php
// 3. Move an existing row within its scope
// Returns false when the row is missing or the final update changed nothing; in
// a caller-owned transaction check it before commit() (see "Who rolls back").
$success = $ordering->moveWithinScope(
    pdo: $pdo,
    config: $config,
    scopeValue: 2, // Use null for global ordering
    id: 15,
    newOrder: 4,
    // Required when updatedAtColumn is configured.
    updatedAtValue: null,
);
```

> **Ordering index requirement.** `moveWithinScope()` is **not compatible with a
> `UNIQUE (scope, order)` index** on the ordering columns: it shifts the rows
> between the old and the new position with a single `UPDATE` per direction, and
> the unique check runs row by row, so ordinary moves fail with duplicate-key
> error `1062` (only no-op moves and some moves over gaps succeed). A `UNIQUE`
> index is still a valid safety net for *allocation inserts*, but do not add it
> to a table whose rows you move with this method. See
> [Unique Indexes and `moveWithinScope()`](#unique-indexes-and-movewithinscope).

### Unreleased: next-position allocation and the deadlock-retry runner

> **Unreleased.** The classes and method below are on `main` only and are not
> part of `v1.4.0`.

Inside a PDO transaction, `getNextPositionForUpdate()` reads the latest
committed maximum with a locking read, which avoids the stale-snapshot duplicate
positions that the plain read can produce under `REPEATABLE READ`:

```php
use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetryTransactionRunner;

$transactions = new PdoDeadlockRetryTransactionRunner($pdo);

$transactions->run(function () use ($pdo, $ordering, $config): void {
    $nextPosition = $ordering->getNextPositionForUpdate(
        pdo: $pdo,
        config: $config,
        scopeValue: 2, // Use null for global ordering
    );

    // Insert the new row at $nextPosition using the same PDO connection.
});
```

### Composing PDO Mutations in One Transaction

Use the transaction runner when several mutations must commit or roll back
together. Every participant must use the same PDO connection. When the runner
starts the transaction (no transaction is active), a failure rolls back every
mutation in the callback. The released `v1.4.0` class for this is
`PdoTransactionRunner`; the example below uses the Unreleased
`PdoDeadlockRetryTransactionRunner`, which behaves the same at its default
configuration:

```php
use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetryTransactionRunner;

$transactions = new PdoDeadlockRetryTransactionRunner($pdo);

$transactions->run(function () use ($pdo, $ordering, $config): void {
    $pdo->prepare('UPDATE `consumer_table` SET `status` = :status WHERE `id` = :id')
        ->execute(['status' => 'ready', 'id' => 10]);

    // false (row missing, or the final update changed nothing) must not be
    // ignored: throwing makes the runner roll back the whole owned transaction.
    if ($ordering->moveWithinScope($pdo, $config, 2, 15, 4) === false) {
        throw new RuntimeException('moveWithinScope() returned false.');
    }
});
```

### Who rolls back: owned vs caller-owned transactions

* **Operation-owned (no active transaction).** The runner, or
  `moveWithinScope()` itself, begins the transaction. On failure it rolls the
  whole transaction back, so partial updates do not survive.
* **Caller-owned (a transaction is already active).** The runner and
  `moveWithinScope()` only participate: they never commit and never roll the
  transaction back. If an operation fails part-way, **the changes it already
  made stay in your transaction**. For `moveWithinScope()` that means the
  shifted rows of an interrupted move (for example after a failure in the final
  update of the target row, or after `false` from the final target update) can
  remain visible and be committed by your own `commit()`. The caller must decide
  to roll back the entire transaction (or to restart it).
* **No savepoint guarantee in the ordering API.** `moveWithinScope()` does not
  create a savepoint, so it does not undo its own partial work inside a
  caller-owned transaction. Wrapping the call in the Savepoint runner (below)
  isolates statement-level failures only, never a deadlock (`1213`), and is the
  caller's choice, not a built-in guarantee of the ordering API.

`TransactionRunnerInterface` exposes only `run(callable $callback)`, so a
consumer service can depend on the shared transaction abstraction without
knowing about PDO. The released `v1.4.0` provides two PDO implementations with
different behavior (`PdoTransactionRunner` and `PdoSavepointTransactionRunner`);
neither replaces the other. The Unreleased generation below keeps the same two
behaviors and adds optional deadlock replay.

### Unreleased: `PdoDeadlockRetryTransactionRunner`
* owns begin/commit/rollback when no transaction is active
* participates in an existing caller-owned transaction without begin/commit/full rollback
* intended when operation-local savepoint isolation is not required

### Unreleased: `PdoDeadlockRetrySavepointTransactionRunner`
* preserves normal owned-transaction behavior when no transaction is active
* when an outer transaction is active, creates an operation-local savepoint
* on callback failure, attempts best-effort rollback to the operation savepoint without fully rolling back the caller-owned outer transaction
* never commits or fully rolls back the caller-owned outer transaction
* outer transaction remains caller-owned
* same PDO connection is required
* intended when operation-local rollback isolation is required
* isolates **statement-level** failures only (for example `1062` or `1205`); it is **not** protection against a deadlock (`1213`), which ends the whole caller-owned transaction on the server (see below)

### ⚠️ Deadlock `1213` Is Fatal to the Surrounding Transaction

When MySQL/InnoDB reports a deadlock (`PDOException`, SQLSTATE `40001`, driver
code `1213`) it has already rolled back the **entire** transaction of the victim
on the server. This holds for a caller-owned transaction too, including the work
the caller did before it called the runner, and a savepoint does not help: the
savepoint disappears with the transaction.

* **Never continue in that transaction.** Do not catch the `1213`, keep
  executing statements and then `commit()`. Once the transaction is gone,
  `pdo_mysql` can keep reporting `inTransaction() === true` until the next
  successful server reply, so do not use `inTransaction()` to decide whether the
  work survived. Further statements may run in autocommit mode (and be committed
  immediately) or, with `autocommit = 0`, in a new implicit transaction that a
  later `commit()` makes permanent without any error.
* **Do not rely on `DO 0` (or any other probe) to prevent later writes.** The
  Unreleased runners use a `DO 0` probe only to decide, for a transaction they
  own, whether a failure was a deadlock victim; it is not a safety mechanism for
  caller code and does not stop a caller from writing after the deadlock.
* **Propagate `1213` to the code that owns the whole transaction** and re-run
  that whole unit of work from a fresh `BEGIN` when it is safe to do so. A
  retry must wrap the entire runner-owned transaction (for example
  `maxDeadlockRetries` on the Unreleased runner), never only the failed
  operation or a savepoint inside it.
* Catch expected errors by code (`1062`, `1205`, package exceptions) around a
  savepoint runner; do not catch `Throwable`/`PDOException` generically and then
  continue.
* Avoid `autocommit = 0` with these patterns: it turns the failure above into a
  silent commit of only the post-deadlock work.

Evidence (MySQL 8.4.10 and MariaDB 11.8.9 only): [Issue #46 study sources on
PR #45](https://github.com/Maatify/persistence/blob/3b5b71f2868cd6c1451691f036473a870d135c2b/docs/studies/full-fresh-review/DG03-ASSESSMENT.md).

### Optional deadlock replay

Both constructors take `int $maxDeadlockRetries = 0` (allowed range `0` to `3`).
With the default `0` nothing is replayed. With a positive value, a transaction
**the runner owns** is replayed from a fresh `BEGIN` when its callback fails with
a MySQL deadlock (`PDOException`, SQLSTATE `40001`, driver code `1213`):
immediately the first time, then after a random wait of 0-10 ms and 0-20 ms.
After the last attempt the original final `PDOException` is rethrown.

```php
$transactions = new PdoDeadlockRetryTransactionRunner($pdo, maxDeadlockRetries: 3);
```

> **The whole callback runs again on every replay.** Keep effects that a
> `ROLLBACK` cannot undo (e-mails, HTTP calls, queues, files, other connections)
> outside the callback, after `run()` returns, or make them idempotent. Never
> begin, commit, or roll back the runner-owned transaction inside the callback
> (PDO methods or SQL), and never end it through an implicit commit (DDL, or
> session/autocommit changes). Keeping that transaction intact is a
> mandatory precondition of replay: callbacks that end or replace it are outside
> the supported contract and get no transaction-identity guarantee. A transaction
> the callback already ended is not replayed when the connection is outside a
> transaction at the deadlock; if the callback ended it and started another
> transaction that then deadlocks, the runner cannot tell and would replay (see the
> [Package Reference](PERSISTENCE_PACKAGE_REFERENCE.md)).

Nothing else is retried: not lock wait timeouts (`1205`) or other errors, not
`BEGIN`/`COMMIT` failures, and never a caller-owned transaction or savepoint (a
deadlock has already rolled the whole caller transaction back; the caller
decides). A `BEGIN` or `COMMIT` that fails without throwing (PDO returns
`false`) raises `TransactionExecutionException`: the callback never runs
without the transaction, and an uncommitted result is never returned. The value `3` matches the configuration measured in the
[Issue #32 study](docs/studies/issue-32-retry-comparison/ISSUE-42-REPORT.md),
which covers one synchronized 8-connection workload, not every load. This
capability is Unreleased and not part of the stable `v1.4.0`.

`PdoTransactionRunner` and `PdoSavepointTransactionRunner` are deprecated on
`main` (Unreleased deprecation; they are not deprecated in `v1.4.0`) but remain
public and behavior-compatible in `1.x`; see the
[compatibility inventory](PERSISTENCE_PACKAGE_REFERENCE.md#compatibility-deprecated-transaction-runners).

For the detailed behavioral contract and runner selection guidance, see the [PDO Transaction Architecture](docs/architecture/PDO_TRANSACTION_ARCHITECTURE.md).

### PDO Pagination

```php
use Maatify\Persistence\Pdo\Pagination\PaginationConfig;
use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist;
use Maatify\Persistence\Pdo\Pagination\SortDirectionEnum;

$config = new PaginationConfig(
    defaultPerPage: 10,
    maxPerPage: 100,
    minPerPage: 1,
    sortWhitelist: new SortWhitelist([
        'id' => 'id',
        'created' => 'created_at',
        'name' => 'user_name',
    ]),
    defaultSortBy: 'created',
    defaultSortDirection: SortDirectionEnum::DESC,
    tieBreakerSortBy: 'id',
    tieBreakerDirection: SortDirectionEnum::DESC
);

$query = new PdoPaginationQueryDescriptor(
    totalSql: 'SELECT COUNT(*) FROM users',
    totalParams: [],
    filteredCountSql: 'SELECT COUNT(*) FROM users WHERE status = :status',
    filteredCountParams: ['status' => 'active'],
    dataSql: 'SELECT id, user_name, created_at FROM users WHERE status = :status',
    dataParams: ['status' => 'active']
);

$request = new PageRequest(page: 2, perPage: 15, sortBy: 'name', sortDirection: 'ASC');

$paginator = new PdoPaginator();
$result = $paginator->paginate(
    pdo: $pdo,
    query: $query,
    request: $request,
    config: $config,
    mapper: fn(array $row) => (object) $row
);
```

## 🧩 Public Runtime API

The released `v1.4.0` provides the following public classes for PDO ordering, transactions, and pagination:

```php
Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
Maatify\Persistence\Pdo\Transaction\TransactionRunnerInterface;
Maatify\Persistence\Pdo\Transaction\PdoTransactionRunner;
Maatify\Persistence\Pdo\Transaction\SavepointTransactionRunnerInterface;
Maatify\Persistence\Pdo\Transaction\PdoSavepointTransactionRunner;

Maatify\Persistence\Pdo\Pagination\PageRequest;
Maatify\Persistence\Pdo\Pagination\SortDirectionEnum;
Maatify\Persistence\Pdo\Pagination\SortWhitelist;
Maatify\Persistence\Pdo\Pagination\PaginationConfig;
Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
Maatify\Persistence\Pdo\Pagination\PageResult;
Maatify\Persistence\Pdo\Pagination\PdoPaginator;

// Exceptions
Maatify\Persistence\Exception\PersistenceException;
Maatify\Persistence\Exception\InvalidOrderingConfigurationException;
Maatify\Persistence\Exception\InvalidOrderingOperationException;
Maatify\Persistence\Exception\OrderingTransactionException;
Maatify\Persistence\Exception\InvalidPaginationConfigurationException;
Maatify\Persistence\Exception\InvalidPaginationQueryException;
Maatify\Persistence\Exception\PaginationExecutionException;
Maatify\Persistence\Exception\TransactionExecutionException;
```

**Unreleased** (on `main`, not in `v1.4.0`): `ScopedOrderingManager::getNextPositionForUpdate()`,
and the classes below.

```php
Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetryTransactionRunner;
Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner;
Maatify\Persistence\Exception\InvalidTransactionConfigurationException;
```

`PdoTransactionRunner` and `PdoSavepointTransactionRunner` remain public and
autoloadable for backward compatibility with `1.x` consumers. On `main` they are
deprecated in favor of `PdoDeadlockRetryTransactionRunner` and
`PdoDeadlockRetrySavepointTransactionRunner` (Unreleased).

`OrderingTransactionException` remains public and autoloadable for backward
compatibility with `1.x` consumers, but is deprecated. `moveWithinScope()` now
participates in an active caller-owned PDO transaction and no longer throws it
for that condition.

## ⚠️ Critical Runtime Behavior

**Choosing between the two next-position reads** (both are supported):
* `getNextPositionForUpdate()`: allocate a position for an insert inside a caller-owned transaction, in particular under `REPEATABLE READ` (the MySQL default), where a plain read can be stale.
* `getNextPosition()`: plain, lock-free read for previews, and for allocation only when no older snapshot exists: outside a transaction, under `READ COMMITTED`, or when the caller's serialization lock (a lock that every competing writer takes first, for example on a parent row) is taken before the transaction's first non-locking read. Concurrent allocation still needs that caller-side serialization.

**`getNextPosition()`:**
* Does not start a transaction.
* Does not lock the applicable scope.
* For concurrent inserts, the host application must provide the transaction and locking mechanism required to serialize position allocation.
* Snapshot rule: under `REPEATABLE READ` the plain read uses the snapshot created by the transaction's first non-locking read, and locking reads (`FOR UPDATE`) do not refresh it. If another connection committed after that snapshot, the plain read can return a stale maximum even when the caller locked the scope or a parent row afterwards, and repeating it in the same transaction (for example to retry after a duplicate-key error) returns the same stale value. Use `getNextPositionForUpdate()` in that case, or retry in a new transaction.

**`getNextPositionForUpdate()` (Unreleased, not in `v1.4.0`):**
* Requires an active PDO transaction on the given connection; throws `InvalidOrderingOperationException` otherwise. It never begins, commits, or rolls back a transaction.
* Performs a locking read (`SELECT ... ORDER BY order DESC, id DESC LIMIT 1 FOR UPDATE`) of the last active row in the applicable scope, repeated until two consecutive reads return the same order value, so the result reflects the latest committed state instead of the transaction snapshot. The lock is held until the caller ends the transaction; insert on the same connection and transaction (see the transaction example above).
* Guarantee: on a non-empty scope, callers that also use locking reads (`getNextPositionForUpdate()`, `moveWithinScope()`) are serialized on the scope's last active row.
* Limits: an empty scope has no row to lock, so the host application must still serialize the first insert (for example by locking a parent row, or by using a `UNIQUE` constraint as a safety net that turns a duplicate insert into an error; see the `moveWithinScope()` incompatibility below). Lock footprint, gap locking, repeated-read lock retention, and lock wait timeouts or deadlocks (retry is the caller's responsibility, except the opt-in deadlock replay of an owning `PdoDeadlockRetryTransactionRunner`) are detailed in the [Package Reference](PERSISTENCE_PACKAGE_REFERENCE.md).

**`moveWithinScope()`:**
* Rejects inconsistent scope usage.
* Rejects `id <= 0`.
* Rejects `newOrder <= 0`.
* Owns a transaction when called without an active PDO transaction.
* Participates in an active caller-owned PDO transaction without beginning, committing, or rolling it back.
* **Not compatible with a `UNIQUE (scope, order)` index** (see [Unique Indexes and `moveWithinScope()`](#unique-indexes-and-movewithinscope)).
* Locks the applicable active scope using `SELECT ... FOR UPDATE`.
* Derives the clamp maximum from the rows returned by that same locking read, not from a separate non-locking read. Inside a caller-owned `REPEATABLE READ` transaction whose snapshot is older than another connection's commit, a move therefore clamps against the latest committed rows that the locking read observed (rows 1-3 in the snapshot, rows 4-5 committed later: moving the first row to position 5 reaches 5). This is not a guarantee that later concurrent inserts are serialized (for example under `READ COMMITTED`). Unreleased; not part of the stable `v1.4.0`. See [How to Use: Moving a Row Inside an Ordered Scope](docs/guides/MOVE_WITHIN_SCOPE_HOW_TO_USE.md). It is separate from `getNextPositionForUpdate()`, which allocates the position of a new row.
* Supports `NULL` as a scope value when `nullableScope` is enabled; this is distinct from global ordering, which has no `scopeColumn`.
* Reads the current order from the database within the same transaction.
* Does not trust a current order provided by the caller.
* Returns `false` if the target row is missing.
* Clamps values higher than the maximum position to the maximum available position.
* Returns `true` if the movement is a no-op (already at the requested position).
* Moves only the affected range.
* When `updatedAtColumn` is configured, updates that column on the target row in the same SQL statement and transaction as the final order update.
* Does not globally normalize pre-existing gaps.
* In a transaction it owns: rolls back and returns `false` if the final target update reports no affected row, and rolls back on any Throwable after starting the transaction, so no partial shift survives.
* In a caller-owned transaction: it never rolls back. Rows it already shifted stay in the caller's transaction when it returns `false` after the shifts or throws; the caller decides on a full rollback. It creates no savepoint, so there is no savepoint-rollback guarantee.
* Rethrows the original Throwable without arbitrary wrapping.

### Unique Indexes and `moveWithinScope()`

`moveWithinScope()` shifts the rows between the current and the new position
with one `UPDATE ... SET order = order ± 1` and then writes the target row.
InnoDB checks a unique index row by row while the `UPDATE` runs (the check is not
deferred), so a `UNIQUE (scope, order)` index rejects the intermediate state with
duplicate-key error `1062`. In the study on `main`, this failed for most moves
(for sequential positions only no-op moves passed), inside and outside a
caller-owned transaction.

* Do not combine `moveWithinScope()` with a `UNIQUE` index on
  `(scope, order)` (including a unique index that covers soft-deleted rows).
  Use a non-unique index for `(scope, order)` and serialize writers through the
  locking APIs and, where needed, a parent-row lock.
* This is a limit of the current method, not advice to drop unique indexes
  everywhere: a `UNIQUE` index remains a valid safety net for allocation inserts
  in tables whose rows are never moved with this method, or if you move rows
  with your own SQL.
* The Issue #46 study found one candidate approach (named *B3*: park the target
  at the maximum position over **all** rows read with a locking read plus one,
  shift in descending/ascending order, then write the final position, inside a
  savepoint) that works under a unique index with stated conditions (plain
  unique index without position-holding soft-deleted rows, or an "active-only"
  generated-column index; the position stays below the column type's maximum;
  the temporary position is visible to triggers/audit; lock cost grows with the
  moved range; engine behavior differs). **It is not implemented** in this
  package: there is no such API, no commitment to ship it, and any future change
  would follow its own issue and release decision.

**`PdoDeadlockRetryTransactionRunner` (Unreleased):**
* Requires all composed participants to use the same PDO connection.
* Starts, commits, and rolls back the transaction when it owns it.
* Participates in an existing transaction without changing its ownership.
* Preserves the callback return value.
* Rethrows the original callback `Throwable` after attempting to roll back an owned transaction.
* With `maxDeadlockRetries` `1` to `3`, replays an owned transaction after a deadlock (`1213`, SQLSTATE `40001`) as described above; rethrows the exact final `PDOException` after the last attempt. Never replays a caller-owned transaction, a `1205`, a `BEGIN`/`COMMIT` failure, or a transaction that already ended before the deadlock. A value outside `0` to `3` throws `InvalidTransactionConfigurationException` from the constructor.
* Throws `TransactionExecutionException` when `beginTransaction()` or `commit()` returns `false` instead of throwing (the deprecated runner ignored these return values).

**`PdoDeadlockRetrySavepointTransactionRunner` (Unreleased):**
* no-active-transaction path uses normal owned transaction behavior, including the optional deadlock replay
* active outer transaction uses an operation-local savepoint
* after a successful callback, the runner releases the operation savepoint; the callback result is returned only when completion succeeds
* after a callback failure, the runner attempts best-effort rollback to the operation savepoint while preserving caller ownership of the outer transaction; this covers statement-level errors, **not** a deadlock (`1213`), after which the savepoint no longer exists (`ROLLBACK TO SAVEPOINT` fails) because the server rolled back the whole transaction
* cleanup failures never replace the original callback `Throwable`
* the runner never commits or fully rolls back the caller-owned outer transaction
* the savepoint path never replays anything, including after a deadlock
* same PDO connection requirement

**`rowExistsInScope()`:**
* Returns `false` for `id <= 0`.
* Returns `false` if the row is not found within the configured scope.
* Treats soft-deleted rows as non-existent if `deletedAtColumn` is configured.
* Throws `InvalidOrderingOperationException` on invalid scope usage.
* External PDO errors propagate unmodified.

**PDO Pagination:**
* Normalizes page and per-page limits strictly.
* Uses safe whitelist-based sorting.
* Host application owns SQL, scopes, mapping, and filters.
* Package handles count queries and offset calculation.
* Does not alter or require active PDO transactions.

## 🏛️ Architecture Guarantees

* Standalone Composer package.
* Framework-agnostic.
* Host-agnostic.
* PDO-based.
* No ORM.
* No framework bindings.
* No HTTP endpoints, UI, controllers, or routes.
* No host table ownership.
* No generic application repository abstraction.
* The host provides the PDO connection.
* The caller owns the outer transaction when composing ordinary PDO mutations with Ordering mutations.
* Trusted SQL identifiers.
* Runtime values use prepared statements.

## 🛡️ Exception and Error Propagation

All package-defined exceptions implement the marker interface `Maatify\Persistence\Exception\PersistenceException`. However, this interface is not a catch-all. `PDOException` or other external `Throwable`s may propagate without wrapping and require a separate catch or an outer `Throwable` boundary if handling is needed.

## 🔐 Security and Trust Boundaries

The `ScopedOrderingConfig` validates and quotes all configured table and column identifiers. However, these identifiers **must** still be provided as trusted application configurations (e.g., constants), never as raw user input. All actual runtime values are safely passed using PDO prepared statements.

## 🧪 Examples

Five standalone, runnable scripts (they use the Unreleased `getNextPositionForUpdate()` and `PdoDeadlockRetryTransactionRunner`, so they need `main`, not `v1.4.0`) cover allocating a position and inserting a row end to end, including rollback, the `REPEATABLE READ` stale-snapshot case, empty scopes, and lock timeouts. They need a real MySQL test database:

```bash
php examples/next-position/01_allocate_and_insert.php
```

See the [How-to-Use guide](docs/guides/NEXT_POSITION_ALLOCATION_HOW_TO_USE.md) for the list, setup, and limits. `getNextPositionForUpdate()` is Unreleased and is not part of the stable `v1.4.0`.

A separate runnable script covers **moving** a row (`moveWithinScope()`) inside a caller-owned `REPEATABLE READ` transaction whose snapshot is older than another connection's commit (rows 1-3 in the snapshot, rows 4-5 committed meanwhile, first row moved to position 5):

```bash
php examples/move-within-scope/01_move_after_older_snapshot.php
```

See [How to Use: Moving a Row Inside an Ordered Scope](docs/guides/MOVE_WITHIN_SCOPE_HOW_TO_USE.md). The movement fix is Unreleased and is not part of the stable `v1.4.0`; the allocation examples above do not exercise the movement path.

Two runnable scripts produce a **real** InnoDB deadlock with a second process and show the opt-in deadlock replay: an owned transaction replayed and committed once (side effect after commit runs once), and a caller-owned transaction that is never replayed:

```bash
php examples/deadlock-retry/01_owned_transaction_replay.php
php examples/deadlock-retry/02_caller_owned_no_replay.php
```

The deadlock-retry runners are Unreleased and not part of the stable `v1.4.0`.

## 📚 Documentation

For a comprehensive guide, please refer to the main technical reference:
* [Persistence Package Reference](PERSISTENCE_PACKAGE_REFERENCE.md)

Practical guides (they document `main`; each marks which parts are Unreleased):
* [How to Use: Allocating the Next Position Safely](docs/guides/NEXT_POSITION_ALLOCATION_HOW_TO_USE.md) (runnable examples in [`examples/next-position`](examples/next-position))
* [How to Use: Moving a Row Inside an Ordered Scope](docs/guides/MOVE_WITHIN_SCOPE_HOW_TO_USE.md) (runnable example in [`examples/move-within-scope`](examples/move-within-scope))
* Deadlock replay: runnable examples in [`examples/deadlock-retry`](examples/deadlock-retry); contract in the [Package Reference](PERSISTENCE_PACKAGE_REFERENCE.md) and [PDO Transaction Architecture](docs/architecture/PDO_TRANSACTION_ARCHITECTURE.md)

Other important documentation:
* [Changelog](CHANGELOG.md)
* [Security Policy](SECURITY.md)
* [Contributing Guide](CONTRIBUTING.md)
* [Code of Conduct](CODE_OF_CONDUCT.md)
* [Architecture Decision Records](docs/adr/README.md)
* [Standards Manifest](docs/php-engineering-standards/STANDARDS_MANIFEST.md)
* [Package Building Standard](docs/php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md)
* [CI Workflow Standard](docs/php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md)
* [Library Presentation Standard](docs/php-engineering-standards/standards/packages/LIBRARY_PRESENTATION_STANDARD.md)

## ✅ Quality Status

* PHP 8.2–8.5 verification in CI.
* PHPStan Level Max.
* Unit, Regression, and MySQL Integration tests.
* Lowest dependencies verification.
* Stable CI Gate.

**Integration testing:**
* Real MySQL is required for Integration tests.
* SQLite is not an Integration substitute.
* MySQL `8.4.10` is the currently verified CI baseline.

## 🛠️ Development and Testing

```bash
composer validate --strict
composer dump-autoload --optimize --strict-psr
composer check-platform-reqs
composer audit --no-interaction --abandoned=fail
composer analyse
composer test:unit
composer test:regression
composer test:integration
composer test:consumer
vendor/bin/php-cs-fixer fix --dry-run --diff
git diff --check
```

`composer test:integration`, `composer test:consumer`, and `composer test` require a real MySQL database. SQLite is explicitly **not** an integration substitute. The Consumer Verification Harness creates a separate Composer root, installs this package as a non-symlinked dependency, and performs two clean runs.

Set the following environment variables for Integration tests:
* `PERSISTENCE_TEST_MYSQL_DSN`
* `PERSISTENCE_TEST_MYSQL_USER`
* `PERSISTENCE_TEST_MYSQL_PASSWORD`

For workflow syntax validation, install actionlint `v1.7.12` with the checksum pinned in `.github/workflows/ci.yml`, then run:

```bash
actionlint -color
```

The CI `workflow-lint` job and this local command cover every workflow under `.github/workflows/`.

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 👤 Author

Engineered by **Mohamed Abdulalim** ([@megyptm](https://github.com/megyptm))<br>
Backend Lead & Technical Architect<br>
[https://www.maatify.dev](https://www.maatify.dev)

---

<div align="center">

[Built with ❤️ by Maatify.dev — Unified Ecosystem for Modern PHP Libraries](https://www.maatify.dev)

</div>
