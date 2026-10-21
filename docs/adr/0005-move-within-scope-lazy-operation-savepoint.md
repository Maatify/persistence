# ADR 0005: Lazy Operation-Local Savepoint in `moveWithinScope()`

- **Status:** Accepted ([Owner consent](https://github.com/Maatify/persistence/issues/55) to the published failure-path behavior change, recorded in Issue #55)
- **Decision date:** 2026-10-21
- **Runtime status:** Implemented; Unreleased (not part of the stable `v1.4.0`). Release target and version are TBD by the Owner.
- **Implementation authorization:** Issue #55; binding design: [`ADDENDUM-1-FINAL-DESIGN.md`](../studies/issue-48-partial-shifts/ADDENDUM-1-FINAL-DESIGN.md) of the merged study (Issue #48, PR #54).
- **Related:** [ADR 0003](0003-transaction-savepoint-orchestration.md) (savepoint conventions, unchanged and reused), [ADR 0004](0004-deadlock-retry-transaction-runner-generation.md) (deadlock-retry runners, unchanged; amended only where it says `moveWithinScope()` behavior is unchanged).

## Context

Inside a caller-owned transaction, `moveWithinScope()` shifts a range of rows and then writes the target row. If the target write throws (`1062`, `1205`, a trigger error) or changes no row (`false`), the shifts stayed in the caller's transaction, and a caller that carried on and committed persisted a corrupt ordering (for example `1:1 2:3 3:4 4:5 5:5`). This is the behavior of the stable `v1.4.0` and it was pinned by the published test `testParticipantFailureLeavesOuterTransactionActiveWithoutIndependentRollback`; it is not an artifact of unreleased work (study ADDENDUM-1 §A1, measured on MySQL 8.0.46, 8.4.10 and MariaDB 11.8.9).

## Decision

1. Keep the signature, return type, and return values (`true`, `true` for a no-op, `false` for a missing row). Exception types: no new class is added and the original `Throwable` of the window is rethrown unchanged, but in a caller-owned transaction `moveWithinScope()` can now throw the existing public `TransactionExecutionException` (from `OperationSavepoint::begin()`, a `false` undo behind `false`, or a `false` `RELEASE` after a successful write), which the `v1.4.0` method could not. Add no public API.
2. In a **caller-owned** transaction, `moveWithinScope()` takes an operation-local savepoint **after** the scope lock (`lockScopeForUpdate()`), the target lookup, the clamp and the no-op check, and **immediately before the first mutating statement** (the range shift). The mutation window is the range shift plus the target write.
   - A `Throwable` in the window: best-effort `ROLLBACK TO SAVEPOINT` and `RELEASE SAVEPOINT`; the **original** `Throwable` is rethrown unchanged, and a cleanup failure never replaces it.
   - The target write reports no row (`false`): `ROLLBACK TO SAVEPOINT` **must** succeed or its failure propagates (a `false` is never returned over partial shifts); `RELEASE` is best effort; then `false`.
   - Success: `RELEASE SAVEPOINT`; if it fails, a best-effort `ROLLBACK TO` and `RELEASE` are attempted and the release failure is thrown (ADR 0003).
3. The savepoint name and control-statement conventions are those of ADR 0003 (`maatify_persistence_sp_<32 hex>`; a control statement returning `false` is a `TransactionExecutionException`).
4. Whether the call participates is read once with `PDO::inTransaction()` before the runner decides, with no statement in between. Without an active transaction the existing owned path is unchanged byte for byte (no savepoint statement).
5. Never commit, fully roll back, retry or re-begin the caller's transaction. The caller's earlier work and its own savepoints are untouched.
6. A small `@internal` helper, `Maatify\Persistence\Pdo\Ordering\Internal\OperationSavepoint`, implements `begin()`, `cleanupAfterFailure()`, `undoOrFail()` and `releaseOrUndo()`. The runners in `src/Pdo/Transaction/**` are not changed.
7. Best-effort dead-transaction guard: right after `SAVEPOINT`, if the driver reports no active transaction, `TransactionExecutionException` is thrown before any mutation. It only detects the dead-transaction state that the `SAVEPOINT` reply reveals (measured: MySQL 8.0.46, 8.4.10, MariaDB 11.8.9); on MariaDB 11.4.7 the server opens a new implicit transaction, the driver reports it as active and the guard does not fire. It is **not** a proof of transaction identity.

## Why the savepoint is lazy (after the scope lock)

`ROLLBACK TO SAVEPOINT` releases the locks taken after the savepoint (L1, all measured engines). A savepoint opened before the scope lock would release the scope lock on failure (L2: scope rows free), letting a concurrent writer slip in while the caller's transaction is still open. Placing it after the lock keeps the lock exactly as `v1.4.0` does (L2 with the final design: locked). It also means a no-op or missing-row call issues no savepoint statement.

## Alternatives Considered

- **Opt-in method** (a new `moveWithinScopeAtomically()`-style API): rejected; it leaves the published method corrupt by default and adds public API.
- **Wrap the mutation window in `PdoDeadlockRetrySavepointTransactionRunner`** (study R1): rejected. The runner swallows cleanup failures to protect the callback's `Throwable`, and here that `Throwable` is the internal "no row" sentinel, so a failed undo would return `false` over partial shifts; and after a swallowed `1213` the runner re-checks `inTransaction()` itself and takes its owned path, beginning and rolling back a transaction inside a call that was supposed to participate.
- **A runner for exceptions plus ordering-local code for `false`** (R2): two mechanisms for one window and the same ownership-switch gap.
- **Refactor the two runners onto the helper** (R4): out of scope; it would touch the runners' source. A possible separate follow-up whose acceptance is their existing tests staying green untouched.
- **Savepoint before the scope lock:** rejected (lock release, above).

## Consequences

- Observable only on failure paths of a caller-owned transaction (state is clean instead of corrupt) plus two extra control statements (`SAVEPOINT`, `RELEASE SAVEPOINT`) per **mutating** move on the success path; a failed path additionally runs `ROLLBACK TO SAVEPOINT` and attempts `RELEASE SAVEPOINT` (and a failing cleanup statement changes the count); no extra statement for a no-op or a missing row.
- This is a **published failure-path behavior change** of a method whose previous failure-path state was pinned by a published test, even though its success path, signature, ownership and savepoint semantics are preserved. It is not described as a free compatibility fix. The SemVer classification and the release version are decided by the Owner at the release gate. The pinned test is rewritten to the new contract; the old expectation is kept only as history here and in the study (`results/matrix-real-v1.4.0.txt`).
- Not rescuable and never special-cased: a deadlock (`1213`), MariaDB `1020` and `1205` with `innodb_rollback_on_timeout=ON` end the caller's whole transaction on the server; the original exception surfaces, no rescue or retry is attempted, and a caller must never swallow them and continue in that transaction.
- No claim is made that a transaction already ended by the server can be restored, and the guard is not transaction-identity verification.
- Residuals: a failed undo after a real failure leaves the partial shifts and the original exception (the caller must roll back); non-transactional side effects are not undone; MariaDB is not a CI target (Issue #51), so `1020` and `innodb_rollback_on_timeout=ON` stay study evidence.
