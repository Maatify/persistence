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
    $ordering->moveWithinScope($pdo, $config, $playlistId, $itemId, 5);
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

If the call throws or returns `false` inside your own transaction, rows it has
already shifted are still part of that transaction: roll the whole transaction
back (as the `catch` above does) instead of committing it.

## Transaction ownership and lock boundaries

* Without an active transaction the manager owns one: it begins it, commits on
  success, and rolls back on any failure or when it returns `false`, so no
  partial shift survives.
* With an active transaction it participates and never commits, rolls back, or
  ends it; you own the final commit or rollback. It does **not** undo its own
  partial work there: if a failure (or `false` after the shifts) happens part-way,
  the rows already shifted stay in your transaction until you roll back, and your
  `commit()` would persist them. The ordering API creates no savepoint and gives
  no savepoint-rollback guarantee. Use the same PDO connection for every
  participant.
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
