# Changelog

All notable changes to `maatify/persistence` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
* `ScopedOrderingManager::moveWithinScope()` no longer clamps the target position against a stale maximum when it participates in a caller-owned `REPEATABLE READ` transaction whose snapshot is older than another connection's commit (Issue #33). In the reproduced case (rows 1–3 in the snapshot, rows 4–5 committed afterwards, move of row 1 to position 5) the target was clamped to 3 instead of 5; positions stayed unique, but the requested position was not reached. The maximum is now derived from the rows returned by the scope's existing `SELECT ... FOR UPDATE` read instead of a separate non-locking `MAX()` read, so the clamp reflects the latest committed rows that locking read observes. This does not make later concurrent inserts serialized (for example under `READ COMMITTED`, where InnoDB generally takes no gap locks). Public API, transaction ownership and scope-lock order are unchanged. On the measured MySQL 8.4.11 fixture with an index on `(scope, order)`, the `EXPLAIN` plan and the lock footprint matched the previous locking query; plans, lock footprints and timings on other schemas, indexes and MySQL versions depend on the consumer schema and were not generalized.

### Added
* `ScopedOrderingManager::getNextPositionForUpdate()`: reads the latest committed maximum with a locking read inside an active caller-owned PDO transaction, avoiding stale-snapshot position allocation (duplicate positions under `REPEATABLE READ`) when used instead of `getNextPosition()`. It requires an active transaction and never begins or ends one, and it repeats the locking read until two consecutive reads return the same order value (at least two locking reads per call) so a call that waited for a lock follows a higher tail committed meanwhile. `getNextPosition()` is unchanged (plain read, no lock) and remains a supported, non-deprecated alternative for previews and for callers with no older snapshot (outside a transaction, under `READ COMMITTED`, or with a serialization lock that every competing writer takes first, taken before the transaction's first non-locking read).
* Movement documentation for the #33 fix (Issue #40): a How-to-Use guide (`docs/guides/MOVE_WITHIN_SCOPE_HOW_TO_USE.md`), a runnable real-MySQL example (`examples/move-within-scope/01_move_after_older_snapshot.php`) run by an integration test and the Consumer Verification Harness, and updated `README.md` and `PERSISTENCE_PACKAGE_REFERENCE.md` `moveWithinScope()` contracts. Documentation, example and test changes only; no runtime or public API change.
* How-to-Use guide (`docs/guides/NEXT_POSITION_ALLOCATION_HOW_TO_USE.md`) and five standalone, MySQL-verified examples (`examples/next-position/`) for position allocation, with integration coverage and Consumer Verification Harness execution. Documentation, examples, and tests only; no runtime or public API change.
* Deadlock-retry transaction runner generation (Issue #32, ADR 0004): `PdoDeadlockRetryTransactionRunner` (implements the unchanged `TransactionRunnerInterface`) and `PdoDeadlockRetrySavepointTransactionRunner` (implements the unchanged `SavepointTransactionRunnerInterface`), both with `__construct(PDO $pdo, int $maxDeadlockRetries = 0)`. With the default `0` they behave like the runners they replace, except that a `BEGIN` or `COMMIT` that fails without throwing (PDO returns `false`) raises `TransactionExecutionException` instead of being ignored, so the callback never runs without the transaction and an uncommitted result is never reported as committed. With `1` to `3`, a transaction the runner itself began is replayed from a fresh `BEGIN` when its callback fails with a MySQL deadlock (`PDOException`, SQLSTATE `40001`, driver code `1213`) and the transaction it began is attributed to the deadlock (the driver still reports it when the deadlock arrives, and a no-op statement then shows it ended); a transaction the callback already ended is not replayed; delays before replay 1, 2 and 3 are none, uniform 0-10 ms and uniform 0-20 ms, and the exact final `PDOException` is rethrown after the last attempt. Lock wait timeouts (`1205`), other errors, `BEGIN`/`COMMIT` failures, caller-owned transactions and savepoints are never replayed. A replay re-executes the whole callback, so effects outside the transaction must stay outside it or be idempotent. The value `3` matches the configuration measured in the Issue #32 study for one synchronized 8-connection workload; it is opt-in, not a default.
* `InvalidTransactionConfigurationException` for a deadlock-retry runner constructed with `$maxDeadlockRetries` outside `0` to `3`.
* Runnable real-deadlock examples (`examples/deadlock-retry/`), executed by an integration test and the Consumer Verification Harness, which also verifies the deprecated runners' compatibility from a clean Composer project.

### Changed
* Documentation corrections (Issue #46; documentation only, no `src/`, test or public API change): `moveWithinScope()` is documented as not compatible with a `UNIQUE (scope, order)` index (ordinary moves fail with `1062`), without advising unique indexes be dropped for allocation-only tables; rollback ownership is distinguished between operation-owned transactions (rolled back) and caller-owned transactions (partial work remains until the caller rolls back; no savepoint guarantee in the ordering API); a deadlock (`1213`) is documented as fatal to the surrounding transaction, not covered by a savepoint, and never to be continued; the README Quick Usage now uses only APIs of the released `v1.4.0`, with Unreleased APIs listed and labeled separately. A conditional approach for unique indexes found by the study (PR #45) is recorded as a not-implemented candidate only.

* Documentation (Issue #52; documentation only, no `src/` behavior, test, public API or database-setting change): the ordering guarantees are documented as engine-conditional. With MariaDB `innodb_snapshot_isolation=ON` (the 11.8 default), the stale-snapshot `getNextPositionForUpdate()` and the caller-owned `moveWithinScope()` scope lock raise the engine's `1020` unchanged, and the server rolls back the caller's whole transaction (also in the stable `v1.4.0` for `moveWithinScope()`); callers must not catch it and continue. Safe consumer options and their limits are listed in the README, Package Reference, both How-to guides and the method DocBlocks, based on the [Issue #47 study](docs/studies/issue-47-mariadb-snapshot/REPORT.md).

### Deprecated
* `PdoTransactionRunner` and `PdoSavepointTransactionRunner`, in favor of `PdoDeadlockRetryTransactionRunner` and `PdoDeadlockRetrySavepointTransactionRunner`. Both remain public, autoloadable, and signature- and behavior-compatible throughout `1.x`; their interfaces are unchanged and not deprecated. `ScopedOrderingManager::moveWithinScope()` keeps its behavior and does not replay deadlocks.

## [1.4.0] - 2026-09-18

### Added
* Transaction savepoint orchestration capability (`SavepointTransactionRunnerInterface`, `PdoSavepointTransactionRunner`) for safe, operation-local rollback boundaries within caller-owned PDO transactions.
* `TransactionExecutionException` for package-detected non-throwing failures of transaction control statements.

## [1.3.0] - 2026-09-10

### Added
* Reusable PDO transaction runner with callback result preservation and explicit ownership/participation semantics.

### Changed
* `moveWithinScope()` now participates in an active caller-owned PDO transaction while preserving standalone transaction ownership and scope locking.

### Deprecated
* `OrderingTransactionException` remains public and autoloadable for backward compatibility with `1.x` consumers, but `moveWithinScope()` no longer throws it for an active caller-owned PDO transaction.

## [1.2.0] - 2026-09-06

### Added
* Nullable scoped ordering for schemas where `NULL` is a meaningful scope value.
* Atomic target mutation timestamps for `moveWithinScope()` via `updatedAtColumn` and `updatedAtValue`.

### Changed
* Ordering transaction cleanup now checks that the PDO transaction is still active before rolling it back.

## [1.1.0] - 2026-07-12

### Added
* PDO Pagination capability including `PageRequest`, `PageResult`, and `PdoPaginator`.
* Deterministic offset pagination with strict normalization and safe whitelist-based sorting.
* `InvalidPaginationConfigurationException`, `InvalidPaginationQueryException`, and `PaginationExecutionException`.

## [1.0.0] - 2026-07-11

### Added
* General Maatify PHP library repository presentation standard covering README structure, badges, governance-document identity, release-facing metadata, and final release-candidate review.
* Standalone PDO scoped-ordering package.
* Global and scoped ordering.
* SQL identifier validation and quoting.
* Configurable id, order, scope, and deleted-at columns.
* Optional soft-delete filtering across applicable ordering operations.
* Next-position lookup capability.
* Scoped row-existence lookup.
* Transaction-owned movement operations.
* Scope locking capability (`SELECT ... FOR UPDATE`).
* Position clamping logic.
* Package exception marker (`PersistenceException`).
* Integration with `maatify/exceptions`.
* Unit, Regression, and MySQL Integration test suites.
* Rollback failure-injection coverage.
* PHP compatibility CI.
* Lowest-supported dependency CI.
* MySQL repeatability/residue verification.
* Stable CI Gate.
* Package reference documentation (`PERSISTENCE_PACKAGE_REFERENCE.md`).
* Security policy (`SECURITY.md`).
* Affected-range-only movement without globally normalizing pre-existing gaps.

### Changed
* Refined exception architecture to distinguish between runtime validation (`InvalidOrderingOperationException`), configuration errors (`InvalidOrderingConfigurationException`), and operational constraints (`OrderingTransactionException`).
* Rolls back owned transactions after operation failures and rethrows the original throwable.
* Enforced real MySQL testing; SQLite substitution is explicitly disabled.

[Unreleased]: https://github.com/Maatify/persistence/compare/v1.4.0...HEAD
[1.4.0]: https://github.com/Maatify/persistence/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/Maatify/persistence/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/Maatify/persistence/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/Maatify/persistence/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Maatify/persistence/releases/tag/v1.0.0
