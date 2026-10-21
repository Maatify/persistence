# How to Use: Moving a Row Inside an Ordered Scope

A practical consumer guide for `ScopedOrderingManager::moveWithinScope()`, in
particular when it runs inside a transaction that you own and whose
`REPEATABLE READ` snapshot is older than another connection's commit.

This guide shows how to use the API. It does not restate the contract; the
[Package Reference](../../PERSISTENCE_PACKAGE_REFERENCE.md) remains the source
of truth for exact semantics and exceptions.

## Availability

> **The movement fix described here is Unreleased.** It was made for Issue #33
> after the stable `v1.4.0` release (see the [Changelog](../../CHANGELOG.md),
> `[Unreleased]` → Fixed), so it is **not part of the stable `v1.4.0`**
> package. On `v1.4.0`, `moveWithinScope()` clamps against a separate
> non-locking maximum, which is stale in the case below. The public API,
> signature and transaction ownership are unchanged. The method itself (without
> this fix) is part of `v1.4.0`; the runnable example exercises the Unreleased
> behavior.

## What it does

`moveWithinScope($pdo, $config, $scopeValue, $id, $newOrder, $updatedAtValue)`
moves one row to `$newOrder` and shifts only the rows between its old and new
position. It first locks the active rows of the scope with
`SELECT ... FOR UPDATE`, reads the target row's current order, and clamps
`$newOrder` to the **maximum order of the rows that same locking read
returned**. Because a locking read returns the latest committed rows, the clamp
follows them even when your transaction's consistent-read snapshot is older.

## The case it fixes

Under `REPEATABLE READ` (the MySQL default) your transaction's snapshot is taken
by its first non-locking read. Locking reads (`FOR UPDATE`) and the `UPDATE`s
that `moveWithinScope()` issues see newer committed rows, but a plain read does
not.

1. Your transaction reads (snapshot): rows at positions 1, 2, 3.
2. Another connection commits rows at positions 4 and 5.
3. Your transaction calls `moveWithinScope()` for the row at position 1 with
   `$newOrder = 5`.

The scope lock sees all five rows, so the target is clamped to 5 and the result
is `2, 3, 4, 5, 1` (the moved row at 5). Before the fix the clamp used the
snapshot maximum (3), so the row stopped at position 3 even though position 5
existed.

## Runnable example

[`examples/move-within-scope/01_move_after_older_snapshot.php`](../../examples/move-within-scope/01_move_after_older_snapshot.php)
runs this scenario on two real MySQL connections, creates and drops its own
temporary tables, asserts what it claims, and exits non-zero on any mismatch. It
shows: (1) the stale-snapshot move reaching position 5 inside a caller-owned
transaction that the caller then commits, (2) a caller rollback restoring the
rows because the manager never ends your transaction, and (3) the same call
without a caller transaction, where the manager owns its own.

`examples/move-within-scope/_support.php` and `examples/next-position/_support.php`
are example scaffolding only (connection, temporary tables, assertions); they are
not package API.

Prerequisites: PHP 8.2+, `ext-pdo_mysql`, Composer, and a real MySQL database
(SQLite is not a substitute; MariaDB is not part of this repository's verified
matrix). Use a disposable test database, never production:

```bash
composer install
export PERSISTENCE_TEST_MYSQL_DSN="mysql:host=127.0.0.1;port=3306;dbname=your_test_database;charset=utf8mb4"
export PERSISTENCE_TEST_MYSQL_USER="your_test_user"
export PERSISTENCE_TEST_MYSQL_PASSWORD="your_test_password"
php examples/move-within-scope/01_move_after_older_snapshot.php
```

Expected output ends with `OK: 01_move_after_older_snapshot`.

The example is verified automatically so documentation drift fails the build:
`tests/Integration/Examples/MoveWithinScopeExamplesTest.php` runs it as a real
PHP process against MySQL, `composer test:consumer` runs it from a clean,
non-symlinked Composer consumer, and
`tests/Integration/Pdo/Ordering/ScopedOrderingManagerMoveStaleSnapshotTest.php`
covers the same fix in depth (global, scoped, nullable scope, soft deletes,
no-op, gaps, `updatedAtColumn`, rollback, lock boundaries).

## Usage

Move inside a transaction you own:

```php
$pdo->beginTransaction();
try {
    // ... your own reads and writes on the same connection ...
    $moved = $ordering->moveWithinScope($pdo, $config, $playlistId, $itemId, 5);

    // false means the row is missing or the final update changed nothing. The
    // move has undone its own shifts (operation-local savepoint) or thrown, but
    // the outcome of the rest of your unit of work is still yours: here we
    // treat false as a failure and roll the whole transaction back.
    if ($moved === false) {
        throw new RuntimeException('moveWithinScope() returned false; rolling back.');
    }

    $pdo->commit();
} catch (Throwable $throwable) {
    // Best effort: after a deadlock (1213) the server has already rolled back,
    // inTransaction() may be stale and rollBack() may itself throw. Never let
    // cleanup replace the original exception.
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable) {
        // ignored on purpose
    }

    throw $throwable;
}
```

Or call it without a transaction and the manager begins and commits one itself.

If the call returns `false` inside your own transaction, or throws a recoverable
statement-level error (for example `1062`, or `1205` without
`innodb_rollback_on_timeout=ON`), the move has normally undone its own shifts
(Unreleased, Issue #55) and your earlier work is untouched. That holds only when
the transaction is still alive and the rollback to the move's savepoint
succeeded. A thrown exception is **not proof** of that: if the cleanup failed the
original exception still wins and the partial shifts stay; after `1213`, MariaDB
`1020` or an aborting `1205` the server has already erased your whole
transaction. So do not catch-and-continue unless you know the state; rolling the
whole transaction back (as the `catch` above does) is always safe.

## Transaction ownership and lock boundaries

* Without an active transaction the manager owns one: it begins it, commits on
  success, and rolls back on any failure or when it returns `false`, so no
  partial shift survives.
* With an active transaction it participates and never commits, rolls back, or
  ends it; you own the final commit or rollback. It undoes its own partial work
  there (Unreleased, Issue #55, [ADR 0005](../adr/0005-move-within-scope-lazy-operation-savepoint.md)):
  it takes an operation-local savepoint after the scope lock and before the
  first mutating statement, rolls back to it when the shift or the target write
  throws (the original exception is rethrown) or returns `false`, and releases
  it on success. Cleanup is best effort after an exception: if it fails, the
  original exception still wins and the shifts stay for your rollback. If the
  undo behind `false` fails, that failure is thrown (never `false` over partial
  shifts). The scope lock is kept until you end your transaction. A mutating
  move adds two statements (`SAVEPOINT`, `RELEASE SAVEPOINT`); a no-op or a
  missing row adds none. Use the same PDO connection for every participant.
* A deadlock (`1213`) rolls back your **entire** transaction on the server. Do not
  catch it and continue in the same transaction, and do not expect a savepoint to
  cover it; see the
  [Package Reference](../../PERSISTENCE_PACKAGE_REFERENCE.md#deadlock-1213-and-caller-owned-transactions).
* The locks (rows of the applicable scope, matched by scope value, with
  soft-deleted rows excluded when configured) are held until the owning
  transaction ends. Another `moveWithinScope()` or a
  `getNextPositionForUpdate()` on the same scope waits for them and may fail with
  a lock wait timeout or deadlock `PDOException`, which propagates unchanged;
  retrying is your responsibility.
* Under `REPEATABLE READ` the scan can also lock the gap after the last scanned
  index record, which may belong to another scope. Which rows and gaps are
  locked depends on the access plan of your schema and indexes.

## MariaDB: `innodb_snapshot_isolation=ON` fails with `1020`

The clamp fix relies on the scope lock acting as a *current read* inside your
older `REPEATABLE READ` snapshot. MySQL 8.0/8.4 and MariaDB with
`innodb_snapshot_isolation=OFF` do that (tested). MariaDB with the setting `ON`
(the 11.8 default) raises `PDOException` with driver code `1020` instead, and it
does so for the stable `v1.4.0` as well (the scope lock is the same locking
read); it appears only when a row of the scope changed after your snapshot.

* **`1020` ends your whole transaction**: the server has rolled back everything,
  your earlier writes included, and released the locks, while `inTransaction()`
  may still say `true`. Never catch it and keep going; later statements may
  autocommit and survive your `rollBack()`. Propagate it to the owner of the
  transaction and restart the whole unit of work. A savepoint does not help.
* A call that owns its transaction (no active transaction) is not affected, and
  neither is a caller that takes its serialization lock before the first plain
  read.
* Safe options: serialization lock first; `READ COMMITTED` for that transaction;
  or `innodb_snapshot_isolation=OFF` for the connection (it disables the engine's
  lost-update protection for all its statements). The package changes none of
  these for you. Details and tested cells:
  [Package Reference](../../PERSISTENCE_PACKAGE_REFERENCE.md#ordering-guarantees-and-mariadb-innodb_snapshot_isolation).

## Limits

* **Do not use this method on a table with a `UNIQUE (scope, order)` index.** The
  shifting `UPDATE` is checked row by row, so ordinary moves fail with duplicate-key
  error `1062` (a `UNIQUE` index is still fine as a safety net for allocation
  inserts of tables you do not move with this method). Use a non-unique
  `(scope, order)` index. A conditional approach for unique indexes was studied
  (Issue #46, candidate *B3*) but is **not implemented** here; see the
  [Package Reference](../../PERSISTENCE_PACKAGE_REFERENCE.md#ordering-and-unique-indexes).
* The clamp reflects the rows the locking read observed. It is not a guarantee
  that rows inserted afterwards are serialized, in any isolation level; for
  example under `READ COMMITTED` InnoDB generally takes no gap locks.
* An empty scope has no row to lock, so concurrent first inserts and moves are
  not serialized by this call.
* It does not normalize existing gaps: with positions 1, 2, 5, moving the first
  row to a larger number clamps to 5 and shifts only the positions in between.

## Why `getNextPositionForUpdate()` is not the movement fix

`getNextPositionForUpdate()` allocates the position of a **new** row for an
insert; `moveWithinScope()` **moves an existing row** and does not call it. The
two share the idea of reading the latest committed state with a locking read,
but they are separate operations with separate contracts and separate examples.
Allocation is covered in
[How to Use: Allocating the Next Position Safely](NEXT_POSITION_ALLOCATION_HOW_TO_USE.md)
and `examples/next-position/`; those examples do not exercise the movement path.
