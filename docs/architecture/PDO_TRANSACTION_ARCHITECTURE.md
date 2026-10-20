# PDO Transaction Architecture

This document is the durable architectural reference for the complete PDO Transaction capability in `maatify/persistence`.

> **Current generation (Unreleased):** new code uses `PdoDeadlockRetryTransactionRunner` and `PdoDeadlockRetrySavepointTransactionRunner` (see [Unreleased — Deadlock-retry runner generation](#unreleased--deadlock-retry-runner-generation), [ADR 0004](../adr/0004-deadlock-retry-transaction-runner-generation.md)). The v1.3.0 and v1.4.0 sections below are kept as the historical record of the runners they introduced; their behavior is unchanged, and both runners are now deprecated.

## v1.3.0 — Transaction composition

### Components

- `TransactionRunnerInterface`
- `PdoTransactionRunner`

### PdoTransactionRunner Roles

- **Public API:** Yes.
- **Supported:** Yes, behavior-compatible through the `1.x` line.
- **Deprecated:** Yes, Unreleased (ADR 0004), in favor of `PdoDeadlockRetryTransactionRunner`. It was not deprecated in the released v1.3.0 and v1.4.0.
- **Intended use:** Intended for transaction ownership/participation without operation-local savepoint isolation.

### Behavior when no transaction is active

When invoked and no outer transaction is active, the runner assumes ownership:
- Begins a new transaction.
- Executes the callback.
- Commits on success.
- Rolls back on failure.
- Propagates the original `Throwable`.

### Behavior when a caller-owned transaction is already active

When invoked within an active caller-owned transaction, the runner participates in it:
- No new transaction is begun.
- No commit is issued on success.
- No full rollback is issued on failure.
- The original caller-owned transaction remains active for the caller to manage.

### General Guarantees

- **Callback result preservation:** The return value of the callback is preserved and returned by the runner.
- **Same-PDO requirement:** All operations must use the same underlying PDO connection.
- **Transaction ownership boundary:** The package explicitly differentiates between package-owned and caller-owned transactions, avoiding implicit commits or rollbacks of caller-owned state.

## v1.4.0 — Savepoint orchestration

### Components

- `SavepointTransactionRunnerInterface`
- `PdoSavepointTransactionRunner`
- `TransactionExecutionException`

### PdoSavepointTransactionRunner Roles

- **Public API:** Yes.
- **Supported:** Yes, behavior-compatible through the `1.x` line.
- **Deprecated:** Yes, Unreleased (ADR 0004), in favor of `PdoDeadlockRetrySavepointTransactionRunner`. It was not deprecated in the released v1.4.0.
- **Intended use:** Intended when operation-local savepoint isolation is required inside an existing caller-owned transaction.

> **Note:** Neither runner behavior replaces the other. In v1.4.0 the choice was `PdoTransactionRunner` for standard composition without isolation and `PdoSavepointTransactionRunner` for isolated operations within a broader transaction; the current generation keeps the same two behaviors as `PdoDeadlockRetryTransactionRunner` and `PdoDeadlockRetrySavepointTransactionRunner`.

### Behavior when no transaction is active

If no outer transaction is active, `PdoSavepointTransactionRunner` preserves normal owned-transaction semantics (identical to `PdoTransactionRunner`).

### Behavior when an outer transaction is active

If an outer transaction is active, the runner establishes an operation-local boundary:
- Creates an operation-local savepoint before executing the callback.
- Releases the savepoint on success.
- Rolls back to the savepoint on operation failure.
- The outer transaction remains active.
- No commit or full rollback of the caller-owned transaction occurs.

### Savepoint Contracts

- **Naming contract:** `maatify_persistence_sp_<32 lowercase hexadecimal characters>`.
- **Nesting:** Nested and repeated savepoints are fully supported and safely scoped.
- **Callback return-value preservation:** The callback's result is returned unchanged only when the transaction/savepoint completion path succeeds. If the callback succeeds but `RELEASE SAVEPOINT` fails, the callback result is not returned; the original release failure is propagated.
- **Failure Precedence:** The exact behavior is dependent on where the failure occurs:
  - **Savepoint creation / generation:**
    - Savepoint-name generation failure occurs before callback execution and propagates.
    - `SAVEPOINT` creation failure prevents callback execution and propagates.
    - PDO `false` for a primary transaction-control statement is represented by `TransactionExecutionException`.
  - **Callback failure:**
    - The exact callback `Throwable` always wins.
    - If the outer transaction remains active, perform best-effort `ROLLBACK TO SAVEPOINT`.
    - Only after successful rollback-to, perform best-effort `RELEASE SAVEPOINT`.
    - Cleanup failures must never replace the callback `Throwable`.
  - **Successful callback + RELEASE failure:**
    - Success must not be reported.
    - The original RELEASE failure remains visible.
    - If the outer transaction is still active, perform best-effort rollback to the same savepoint.
    - If rollback-to succeeds, perform best-effort release.
    - Cleanup failures must never replace the original RELEASE failure.
    - Never commit or fully roll back the caller-owned outer transaction.
- **TransactionExecutionException:** Surfaced for package-detected non-throwing primary control-statement failures, but cleanup attempts may intentionally suppress their own failures to preserve the higher-priority callback/release `Throwable`.
- **Same-PDO requirement:** Savepoint orchestration is strictly connection-local.
- **MySQL 8.4 verified boundary:** The savepoint behavior is verified strictly against MySQL 8.4 boundaries.
- **Cross-connection out of scope:** Cross-connection and distributed transactions are explicitly out of scope.

## Unreleased — Deadlock-retry runner generation

Decision: [ADR 0004](../adr/0004-deadlock-retry-transaction-runner-generation.md), [Issue #32](https://github.com/Maatify/persistence/issues/32). Not part of the stable `v1.4.0`.

### Components

- `PdoDeadlockRetryTransactionRunner` implements the unchanged `TransactionRunnerInterface`.
- `PdoDeadlockRetrySavepointTransactionRunner` implements the unchanged `SavepointTransactionRunnerInterface`.
- `InvalidTransactionConfigurationException` for an invalid retry limit.
- One internal mechanism (`Internal\OwnedTransactionExecutor`, `@internal`, not public API) implements the owned transaction and its replay for both runners; the deprecated runners do not use it and are unchanged.

Constructor of both: `__construct(PDO $pdo, int $maxDeadlockRetries = 0)`, range `0` to `3`, default `0`. With `0` both behave like the runner they replace, except that a `BEGIN` or `COMMIT` that fails without throwing (PDO returns `false`) raises `TransactionExecutionException` instead of being ignored (the callback does not run without a transaction, and an uncommitted result is never returned).

### Roles

- `PdoDeadlockRetryTransactionRunner`: transaction ownership/participation without operation-local savepoint isolation (the behavior of `PdoTransactionRunner`), plus optional owned-transaction replay.
- `PdoDeadlockRetrySavepointTransactionRunner`: operation-local savepoint isolation inside a caller-owned transaction (the behavior of `PdoSavepointTransactionRunner`, savepoint code unchanged), plus the same optional owned-transaction replay when no transaction is active.

### Owned-transaction replay

Applies only to a transaction the runner begins itself. One attempt is: fresh `BEGIN`, callback, `COMMIT`. Transaction control is fail-closed: `beginTransaction()` returning `false` stops before the callback, and `commit()` returning `false` is never reported as success (best-effort rollback, then `TransactionExecutionException`, no replay). A failed attempt is replayed only when:

1. the **callback** threw a `PDOException` whose `errorInfo` is SQLSTATE `40001` with driver code `1213` (a `BEGIN` or `COMMIT` failure is never replayed);
2. a replay is left (at most `$maxDeadlockRetries`, so at most `$maxDeadlockRetries + 1` attempts);
3. the driver still reports the transaction as active when the deadlock arrives. `pdo_mysql` derives `inTransaction()` from the last successful server reply, so for a victim on this connection it still reports the runner's transaction (the deadlock came as an error reply). If it already reports none, the transaction ended before the deadlock (for example the callback committed it and another connection raised the `1213`), the end cannot be attributed to the deadlock, and the attempt is not replayed;
4. a no-op statement (`DO 0`) then succeeds and the connection reports no active transaction any more (InnoDB rolled back the whole victim transaction). A failing probe, or a transaction that is still active (for example a `1213` raised by another connection while the runner's transaction is open), is not attributed to the deadlock either.

Otherwise the attempt ends like a non-retrying owned transaction: rollback if still active (best effort), original `Throwable` rethrown unchanged.

Delays (Hybrid policy, measured in the Issue #32 study): before replay 1 none, before replay 2 uniform 0-10 ms, before replay 3 uniform 0-20 ms; smaller limits use the prefix. No transaction or lock is held while waiting.

Failure precedence: after the last attempt the exact final `PDOException` is rethrown, never wrapped; a failing `BEGIN` of a replay propagates and ends the attempts; a probe failure never replaces the deadlock.

### Caller-owned transactions and savepoints

- With an active transaction, `PdoDeadlockRetryTransactionRunner` only participates: no `BEGIN`, `COMMIT`, rollback, or replay.
- `PdoDeadlockRetrySavepointTransactionRunner` uses an operation-local savepoint with the unchanged v1.4.0 contract (naming, release, rollback-to, failure precedence) and never replays the savepoint or the caller's transaction.
- A deadlock inside a caller-owned transaction has already rolled back the whole caller transaction on the server; the runner propagates the original `PDOException`, and the caller decides whether to re-run its whole transaction.
- Nested composition: a deadlock in a savepoint inside an owned, replay-enabled transaction replays the whole owned transaction, never only the savepoint.

### Callback replay contract

When replay is enabled the whole callback may run several times. Only its work on the runner's PDO connection inside the owned transaction is undone between attempts; external effects (e-mail, HTTP, queues, files, other connections, in-memory state) are not and must stay outside the callback or be idempotent. The callback must not end or replace the runner-owned transaction, neither explicitly (`commit()`, `rollBack()`, `beginTransaction()`, or SQL such as `START TRANSACTION`, `COMMIT`, `ROLLBACK`) nor implicitly (DDL and other implicit-commit statements, or session/autocommit changes). All participants must use the same PDO connection.

**Callback transaction-ownership precondition (Owner-accepted limit):** keeping the runner-owned transaction intact is a mandatory precondition of replay; callbacks that end or replace it are outside the supported contract and get no transaction-identity guarantee (neither the driver-reported state nor the `DO 0` probe proves transaction identity). A callback that ends the runner-owned transaction and leaves the same connection inside another transaction when a deadlock hits it (an explicit new `BEGIN`, or an implicit commit such as DDL followed by an implicit transaction under `autocommit = 0`) produces the same driver state and session counters as a genuine victim; its already committed work would be replayed (cases M3 and M4). This is unsupported usage, not automatically prevented, and accepted by the Owner ([approval](https://github.com/Maatify/persistence/issues/32#issuecomment-6296393312); [ADR 0004](../adr/0004-deadlock-retry-transaction-runner-generation.md#accepted-limitation-callback-transaction-ownership-precondition)).

### Verification boundary

- Real InnoDB deadlocks (`1213`/`40001`) against a second process on MySQL 8.4 (integration suite and `examples/deadlock-retry`, also executed by the Consumer Verification Harness from a clean Composer project): owned replay and single commit, exhaustion with the exact final `PDOException`, no replay by default, no replay in caller-owned transactions and savepoints, nested replay of the whole owned transaction.
- Unit decision tests for every non-replayable branch, the probe, the delay schedule and bounds, and the range validation; parity tests run the complete v1.4.0 savepoint unit and integration cases against the new savepoint runner configured with `3`.
- Fail-closed control on real MySQL: a `COMMIT` that returns `false` (`ERRMODE_SILENT`, session ended before `COMMIT`) raises `TransactionExecutionException` with nothing committed; a callback that commits the runner-owned transaction before another connection raises a genuine `1213` is not replayed (committed once), while a genuine victim on the runner's connection still is.
- The value `3` and the delay schedule are supported by the Issue #32 study only for one synchronized 8-connection workload; they are opt-in, not a default.
