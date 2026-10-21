# How to Use: Allocating the Next Position Safely

A practical consumer guide for inserting a new row at the end of an ordered
scope with `ScopedOrderingManager::getNextPositionForUpdate()`, and for choosing
between it and `getNextPosition()`.

This guide shows how to use the API. It does not restate the contract; the
[Package Reference](../../PERSISTENCE_PACKAGE_REFERENCE.md) remains the source
of truth for exact semantics, lock footprint, and exceptions.

## Availability

> **`getNextPositionForUpdate()` is Unreleased.** It was merged to `main` in
> PR #34 after the stable `v1.4.0` release, so it is **not part of the stable
> `v1.4.0`** package that `composer require maatify/persistence` installs
> today. It is recorded under `[Unreleased]` in the
> [Changelog](../../CHANGELOG.md) and becomes available to consumers only in a
> future tagged release. Until then, use `dev-main` to try it, or the plain
> `getNextPosition()` with the caveats below. `getNextPosition()` is unchanged
> and remains supported.

The examples also use `PdoDeadlockRetryTransactionRunner` (Unreleased, same
status). On `v1.4.0`, the equivalent runner is `PdoTransactionRunner`, and only
`getNextPosition()` is available for allocation. The scripts under
`examples/next-position/` therefore require the code on `main`, not `v1.4.0`.

## What the examples cover

Five standalone scripts live in [`examples/next-position/`](../../examples/next-position).
Each runs on its own against a real MySQL database, creates and drops its own
temporary tables, asserts what it claims, and exits non-zero on any mismatch.

| Script | What it demonstrates |
| --- | --- |
| [`examples/next-position/01_allocate_and_insert.php`](../../examples/next-position/01_allocate_and_insert.php) | PDO + `ScopedOrderingConfig`, transaction, allocation, real `INSERT`, `COMMIT`; runner-owned and caller-owned transactions |
| [`examples/next-position/02_rollback_on_failure.php`](../../examples/next-position/02_rollback_on_failure.php) | Host failure and `INSERT` failure after allocation: rollback, original error preserved, position free again |
| [`examples/next-position/03_stale_snapshot_vs_plain_read.php`](../../examples/next-position/03_stale_snapshot_vs_plain_read.php) | `getNextPosition()` vs `getNextPositionForUpdate()` under `REPEATABLE READ`, and the `READ COMMITTED` contrast |
| [`examples/next-position/04_empty_scope_and_ownership.php`](../../examples/next-position/04_empty_scope_and_ownership.php) | No-transaction rejection, empty scope, parent-row lock, `UNIQUE` safety net, outer-transaction ownership |
| [`examples/next-position/05_lock_timeout_and_retry.php`](../../examples/next-position/05_lock_timeout_and_retry.php) | Lock wait timeout propagation and a bounded, caller-owned retry policy with its safety conditions and failure boundaries |

`examples/next-position/_support.php` is example scaffolding only (connection,
temporary tables, assertions); it is not package API.

## Run the examples

Prerequisites: PHP 8.2+, `ext-pdo_mysql`, Composer, and a real MySQL database
(SQLite is not a substitute; MariaDB is not part of this repository's verified
matrix). The scripts read the same variables as the integration tests:

```bash
composer install
export PERSISTENCE_TEST_MYSQL_DSN="mysql:host=127.0.0.1;port=3306;dbname=your_test_database;charset=utf8mb4"
export PERSISTENCE_TEST_MYSQL_USER="your_test_user"
export PERSISTENCE_TEST_MYSQL_PASSWORD="your_test_password"
php examples/next-position/01_allocate_and_insert.php
```

The user needs `CREATE`, `DROP`, and DML privileges on that database. Use a
disposable test database, never production: the scripts only touch uniquely
named `maa_example_*` tables and drop them when done.

The examples are also exercised automatically:

* `composer test:integration` runs every script as a real PHP process
  (`tests/Integration/Examples/NextPositionExamplesTest.php`).
* `composer test:consumer` copies them into a clean Composer project that
  installs this package as a non-symlinked dependency and runs them there, twice
  (`tests/Consumer/run.php`).

## The end-to-end workflow

```text
Host input -> ScopedOrderingConfig -> transaction -> getNextPositionForUpdate()
           -> INSERT (same connection) -> COMMIT or ROLLBACK -> observable rows
```

1. **Configure.** Describe the table once. Table and column names are trusted
   constants, never user input; row values are always bound parameters.

   ```php
   $config = new ScopedOrderingConfig(
       table: 'playlist_items',
       scopeColumn: 'playlist_id',   // null for one global sequence
       idColumn: 'id',
       orderColumn: 'position',
       deletedAtColumn: 'deleted_at', // null if the table has no soft delete
   );
   $ordering = new ScopedOrderingManager();
   ```

2. **Open a transaction on the connection you will insert with.**
   `getNextPositionForUpdate()` requires an active PDO transaction and throws
   `InvalidOrderingOperationException` otherwise. It never begins, commits, or
   rolls back anything.

3. **Allocate and insert on the same connection and transaction.**

   ```php
   $transactions = new PdoDeadlockRetryTransactionRunner($pdo);

   $position = $transactions->run(function () use ($pdo, $ordering, $config, $playlistId): int {
       $position = $ordering->getNextPositionForUpdate($pdo, $config, $playlistId);

       $pdo->prepare(
           'INSERT INTO playlist_items (playlist_id, position, title) VALUES (:playlist_id, :position, :title)'
       )->execute(['playlist_id' => $playlistId, 'position' => $position, 'title' => 'Bridge']);

       return $position;
   });
   ```

   The runner commits on success and, on failure, rolls back and rethrows the
   original `Throwable`. If your code already owns the transaction, use plain
   `beginTransaction()` / `commit()` and roll back in a `catch` that rethrows,
   as in example 01.

4. **Commit or roll back.** The lock taken by the allocation is held until the
   transaction ends. Keep the transaction short: allocate, insert, finish.
   Example 02 shows that a failure after allocation leaves nothing behind.

## `getNextPosition()` vs `getNextPositionForUpdate()`

|  | `getNextPosition()` | `getNextPositionForUpdate()` |
| --- | --- | --- |
| Read | plain `SELECT MAX(...)`-style read, no lock | locking read (`FOR UPDATE`) of the scope's last active row |
| Transaction | not required, not started | **required**, never started or ended |
| Reflects | your transaction's snapshot (or the latest state outside a transaction) | the latest committed state |
| Serializes writers | no | yes, on a non-empty scope, among callers that also use locking reads |
| Use for | previews and UI hints; allocation only when no older snapshot exists | allocating a position for an insert inside a transaction |

### Why the plain read can be stale

MySQL's default isolation level is `REPEATABLE READ`. The first non-locking read
in a transaction creates a snapshot, and every later plain read in that
transaction uses it. Locking reads (`FOR UPDATE`) do not refresh it. So if
another connection commits a row after your snapshot, `getNextPosition()`
keeps returning the old maximum plus one even if you locked the scope or a
parent row in the meantime, and repeating it in the same transaction (for
example to retry after a duplicate-key error) returns the same stale number.
Example 03 reproduces exactly this and ends with a duplicate position.

`getNextPositionForUpdate()` uses a locking read, which sees the latest
committed state, and serializes concurrent allocators on the last row.

### Which one should I use?

* **Inserting inside a transaction** (the common case, and the default
  `REPEATABLE READ`): `getNextPositionForUpdate()`.
* **Showing a "next position" preview**, or allocating **outside** a transaction
  or under `READ COMMITTED`, or when a serialization lock that every competing
  writer takes first (for example on a parent row) is taken **before** the
  transaction's first non-locking read: `getNextPosition()` is appropriate.
  Note that under `READ COMMITTED` the plain read is fresh but still not
  serialized; concurrent allocation still needs the caller-side lock.

## Concurrency boundaries you must know

### Empty scope

A locking read of an empty scope has no row to lock, so concurrent first
inserts are **not** serialized by the package. You must serialize them, for
example by locking a parent row first, and/or rely on a `UNIQUE (scope, order)`
index so a duplicate becomes an error. A `UNIQUE (scope, order)` index suits
tables you only *insert* into; `moveWithinScope()` is **not compatible** with it
(ordinary moves fail with `1062`, see the
[Package Reference](../../PERSISTENCE_PACKAGE_REFERENCE.md#ordering-and-unique-indexes)),
so do not add it to a table whose rows you also reorder with that method. Example 04 demonstrates both: a competing
writer waits on the parent-row lock, and without it the `UNIQUE` index rejects
the second writer with error 1062. (Under `REPEATABLE READ` that unserialized
race typically surfaces as a deadlock or lock wait timeout instead; it is an
error either way.)

### Transaction ownership

* `getNextPositionForUpdate()` never begins, commits, or rolls back. The
  transaction is yours, or `PdoDeadlockRetryTransactionRunner`'s when it started it.
* `PdoDeadlockRetryTransactionRunner` participates in an already-active transaction without
  committing it; the outer owner decides the outcome (example 04, part D).
* Everything that must commit together must use the **same PDO connection**.

### Locks

* The lock is held until the transaction ends. Slow work between allocation and
  commit blocks other allocators on that scope.
* Keep an index that serves `(scope, order)`. With it the scan stops at the last
  active row; without it the scan covers, and locks, every row of the table.
  Soft-deleted rows above the last active row are scanned and locked as well.
* Under `REPEATABLE READ` the lock also covers the gap above the last scanned
  row, which can touch a neighbouring scope. A call may repeat the locking read
  and therefore holds locks on every tail it read.
* Writers that do not use locking reads are not serialized. Route every
  position-allocating insert and every reorder through the locking APIs.
* Under `REPEATABLE READ`, a call queued behind a transaction that then inserts
  above the locked row typically fails with a deadlock rather than resuming.

The exact footprint and limits are specified in the
[Package Reference](../../PERSISTENCE_PACKAGE_REFERENCE.md); do not rely on
this summary alone.

### MariaDB: `innodb_snapshot_isolation=ON` makes the stale-snapshot case fail with `1020`

`getNextPositionForUpdate()` returns the latest committed position inside an
older `REPEATABLE READ` snapshot because the engine performs a *current read* for
the locking statement. MySQL 8.0/8.4 and MariaDB with
`innodb_snapshot_isolation=OFF` do (tested). MariaDB with the setting `ON` (the
11.8 default) refuses and raises `PDOException` with driver code `1020`, which
propagates unchanged. The error appears only when the locking read visits a row
another connection committed after your snapshot; with no concurrent change, or a
change in another scope, nothing fails.

* **`1020` ends your whole transaction.** The server has already rolled back all
  of it, including writes made before the call, and released the locks, while
  `inTransaction()` may still return `true`. Never catch it and continue: later
  statements may run in autocommit and survive your `rollBack()`. Propagate it to
  the code that owns the transaction and restart the whole unit of work from a
  fresh `BEGIN` (see the retry rules below; `1020` is not covered by the opt-in
  deadlock replay).
* **Safe options**, in order of preference: take your serialization lock before
  the transaction's first plain read (then no stale snapshot exists); run the
  transaction under `READ COMMITTED` (every read in it becomes non-repeatable);
  or set `innodb_snapshot_isolation=OFF` for the connection if you accept that it
  disables the engine's lost-update protection for all its statements. In every
  case restart on `1020`.
* The package does not set the variable, switch isolation, retry, or fall back
  to the plain read for you. Evidence, tested cells and the untested ones:
  [Package Reference](../../PERSISTENCE_PACKAGE_REFERENCE.md#ordering-guarantees-and-mariadb-innodb_snapshot_isolation).

### Deadlocks and lock wait timeouts are the consumer's responsibility

Waiting for a lock can fail with `PDOException` (MySQL error 1205 for a lock
wait timeout, 1213 for a deadlock). `getNextPositionForUpdate()` does **not**
retry, wrap, or swallow it; it propagates unchanged and is not a
`PersistenceException`.

The one exception is opt-in and limited to deadlocks: a
`PdoDeadlockRetryTransactionRunner` constructed with `maxDeadlockRetries` 1 to 3
replays the whole callback of a transaction **it owns** after a 1213 deadlock
(see the [Package Reference](../../PERSISTENCE_PACKAGE_REFERENCE.md) and
[`examples/deadlock-retry`](../../examples/deadlock-retry)). It never retries
1205, and never retries inside a transaction it does not own. Everything below
still applies to lock wait timeouts, to caller-owned transactions, and to the
safety of whatever the replayed callback does. This feature is Unreleased.

* A deadlock (`1213`) rolls back the **whole** transaction on the server, and
  `inTransaction()` can still report `true` afterwards. Never catch it and keep
  executing statements in that transaction (they may run in autocommit and
  commit immediately). A lock wait timeout rolls back only the failed statement
  by default, so always end the transaction yourself.
* Retry the **entire unit of work in a new transaction**. Never reuse the stale
  allocation or continue in the failed transaction.
* Bound the attempts and retry only 1205 and 1213; let every other error
  (constraint violations, host exceptions) propagate unchanged. Example 05
  contains a small reference policy and proves each of these boundaries:
  success after a blocker commits, exhaustion with the original `PDOException`
  rethrown and no lock leaked, no retry for other errors, and refusal to run
  inside a transaction it does not own.

**When is re-running the unit of work safe?** Only if a rollback undoes
everything it did:

* It must be idempotent from the database's point of view: all its effects live
  inside the transaction, so each attempt starts from a clean state.
* It must not perform effects a `ROLLBACK` cannot undo: sending a message,
  calling an external API, enqueueing a job, writing a file. Run those **after**
  the retry returns, that is, after `COMMIT`, so they happen once even when two
  attempts ran. Example 05 section 6 shows this.
* Only retry in a transaction you own. If an outer caller owns the transaction,
  a deadlock has already rolled back the whole of it (a savepoint does not
  help), and a lock wait timeout leaves it half-done; do not retry inside it and
  do not continue in it. Propagate the error to the owner, which decides and
  re-runs the whole outer unit of work. The example helper refuses to run in that
  situation.

The example policy is host-owned illustration code, not a package feature.
Apart from the opt-in deadlock replay of `PdoDeadlockRetryTransactionRunner`
for transactions it owns, the package performs no retries.

## Coverage and limits of this guide

Proven by the examples against real MySQL (and re-proven from a clean Composer
consumer by `composer test:consumer`): the full allocate / insert / commit and
rollback paths, both ownership styles, the stale-snapshot hazard and its fix,
the `READ COMMITTED` contrast, empty-scope serialization and its `UNIQUE`
safety net, lock timeout propagation, and a caller-side retry policy including
its failure boundaries (bounded attempts, no retry of other errors, refusal
inside a foreign transaction, no leaked locks, side effects only after commit).
Example setup cleanup after a part-way setup failure is verified by
`tests/Integration/Examples/ExampleSchemaCleanupTest.php`.

Deliberately not demonstrated by the examples:

* **Deadlock (1213) reproduction.** A deadlock needs two sessions blocking each
  other and cannot be produced deterministically from a single PHP process. The
  allocation case is covered with separate processes by the package integration
  tests (`ScopedOrderingManagerStaleSnapshotTest`), and
  [`examples/deadlock-retry`](../../examples/deadlock-retry) reproduces a real
  deadlock with a second process; the retry loop here handles 1213 with the same
  classification as 1205.
* **Multi-process throughput or fairness.** The examples prove correctness
  boundaries, not performance.
* **Soft-delete lock footprint and index-less scans.** Described above and in
  the Package Reference, not benchmarked.
* **MariaDB, SQLite, and other isolation levels** (`READ UNCOMMITTED`,
  `SERIALIZABLE`). Not part of this repository's verified matrix. The examples
  run on MySQL only; the MariaDB `1020` behavior above comes from the
  [Issue #47 study](../studies/issue-47-mariadb-snapshot/REPORT.md), not from
  these examples.
* **The `v1.4.0` release itself.** The examples that call
  `getNextPositionForUpdate()` require the Unreleased code on `main`.

## Related reading

* [Package Reference: ordering API, transaction composition](../../PERSISTENCE_PACKAGE_REFERENCE.md)
* [README: critical runtime behavior](../../README.md)
* [PDO Transaction Architecture](../architecture/PDO_TRANSACTION_ARCHITECTURE.md)
