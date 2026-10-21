# Issue #48 — Addendum 1: final design (fix the existing `moveWithinScope()`)

**Status:** design addendum to [`REPORT.md`](REPORT.md), written after the Lead re-evaluation on PR #54 ("Lead re-evaluation after checking released v1.4.0 against current main"). It **supersedes REPORT §8 and §11-Q1/Q2** (the opt-in method is no longer the recommendation). **No Runtime, Public API, test, CI, composer or public-doc file is changed.** Runtime implementation is **not authorized**; the published failure-path behavior change below needs the Owner's explicit consent first. This is not a claim that the change is non-breaking (§A5).

All numbers come from `results/` (re-run for this addendum: `assert-results.sh` → 239 checks, 0 failures).

## A1. Released `v1.4.0` vs `main`: the defect is in the published contract

**Code.** `git diff v1.4.0 origin/main` for the body of `ScopedOrderingManager::moveWithinScope()` is exactly two lines — the #33 clamp (`$maxOrder` now comes from `lockScopeForUpdate()`; the separate `getMaxPosition()` read is gone). Everything that decides the failure path is identical in both: arguments are validated, then `(new PdoTransactionRunner($pdo))->run(...)`; inside an active transaction that runner calls the callback and nothing else (no savepoint, no rollback). The two runners themselves differ between the tag and `main` only by `@deprecated` docblocks (`PdoTransactionRunner.php`, `PdoSavepointTransactionRunner.php`). #33 changed the clamp, not this failure path.

**Behavior.** The same matrix run against the `v1.4.0` source tree (`PROBE_SRC`; `results/matrix-real-v1.4.0.txt`, first line of each engine block names the file loaded) gives the same RED on MySQL 8.0.46, 8.4.10 and MariaDB 11.8.9 as `main`:

| Cell (caller-owned transaction, `v1.4.0` source) | Result |
|---|---|
| C04 `false` after the shifts | `false`; `1:1 2:3 3:4 4:5 5:5`; the caller's commit persists it |
| C06 `1062` on the target write | same partial state, committed |
| C07 `1205` on the target write (default server option) | same |
| C13 second move fails after a first succeeded | corrupt on top of the first |
| C15 swallow a `1213`, call again | later statements autocommit; partial committed |
| C05 `1062` in the shift, C03 missing row | safe (no shift happened / statement rolled back) |
| C10 `1213` | whole transaction lost (engine behavior, not rescuable) |
| C11 MariaDB `1020` (scope lock) | `1020`, transaction lost — `v1.4.0` is affected too (Issue #47) |

**Published tests and docs.** `ScopedOrderingManagerTransactionTest::testParticipantFailureLeavesOuterTransactionActiveWithoutIndependentRollback` exists at the `v1.4.0` tag and the test file is **byte-identical** between the tag and `main` (`git diff --quiet v1.4.0 origin/main -- tests/Integration/Pdo/Ordering/ScopedOrderingManagerTransactionTest.php`). It asserts the partial state (`[1,3,4,4]`, line 116) and passes on both. What the `v1.4.0` documentation said: `moveWithinScope()` "participates in an active caller-owned PDO transaction without beginning, committing, or rolling it back" and the caller owns the final outcome (Package Reference, README). It was **silent** on the failure-path state; the explicit statements ("remain until the caller rolls back … a caller `commit()` makes them permanent", "creates no savepoint") were added after the tag (#46/#49/#52).

**Conclusion.** Partial shifts are present in the published `v1.4.0` behavior and pinned by a published test; they are not an artifact of unreleased #33 work. "No known consumers of post-RC changes" does not make published behavior unpublished, and this study makes no claim about existing consumers.

## A2. Recommended design (one): lazy operation-local savepoint inside the existing `moveWithinScope()`

No new public API. Signature, return type, documented return values (`true` / `true` for a no-op / `false` for a missing row) and the exception types are unchanged. Behavior of the **participating** path (a transaction is already active) changes on failure paths only:

1. Validate, run the **read-only phase exactly as today**: `lockScopeForUpdate()` (scope lock and clamp maximum), `getCurrentOrderForUpdate()`, missing-row `false`, clamp, no-op `true`. None of these is inside a savepoint.
2. **Savepoint placement: after the scope lock (and the target lookup, clamp and no-op check), immediately before the first mutating statement.** The reason is measured, not stylistic: `ROLLBACK TO SAVEPOINT` releases locks taken after the savepoint (L1, all four engines); a savepoint opened *before* the scope lock would release the scope lock on failure (`recipe`, L2: scope rows free), while this placement keeps it (L2 `proto`: locked), as `v1.4.0`/`main` do.
3. `SAVEPOINT maatify_persistence_sp_<32 hex>` (the ADR 0003 naming contract). A non-throwing `false` → `TransactionExecutionException` before any mutation; a thrown failure propagates unchanged. If the driver then reports no active transaction (a swallowed `1213`/`1020` left a dead one), throw `TransactionExecutionException` **before mutating** (C15).
4. Range shift, then target write. Any `Throwable`: best-effort `ROLLBACK TO` + `RELEASE`, rethrow the **original** unchanged; cleanup failures never replace it.
5. Target write reports no row (`false`): `ROLLBACK TO` **must** succeed or its `Throwable` propagates (never `false` over partial shifts, S5); best-effort `RELEASE`; return `false`.
6. Success: `RELEASE`; if it fails, best-effort `ROLLBACK TO` + `RELEASE`, then throw the release failure (ADR 0003).
7. No active transaction: unchanged — the existing runner-owned path, byte for byte (O1–O3, differential parity: 0 mismatches over 4 shapes × 4 engines).
8. Never commit, fully roll back, retry or re-begin the caller's transaction (`Δcommit/rollback = 0/0` in every caller-owned cell). `1213`, MariaDB `1020`, `1205` with `innodb_rollback_on_timeout=ON` surface as their original exceptions, are not claimed rescuable, and are never special-cased by code.

Shape in the existing method (sketch, not applied):

```php
$participating = $pdo->inTransaction();                    // before the runner decides; no statement in between
return (new PdoTransactionRunner($pdo))->run(function () use (...): bool {
    $maxOrder = $this->lockScopeForUpdate(...);            // unchanged, outside the savepoint
    $currentOrder = $this->getCurrentOrderForUpdate(...);  // unchanged
    if ($currentOrder === null) { throw $rollbackResult; } // unchanged: nothing mutated yet
    $newOrder = min($newOrder, max(1, $maxOrder));
    if ($currentOrder === $newOrder) { return true; }      // unchanged
    $savepoint = $participating ? OperationSavepoint::begin($pdo) : null;       // NEW: after lock, before first mutation
    try { /* shiftOrdersUp|Down */ $updated = $this->updateTargetOrder(...); }
    catch (Throwable $t) { $savepoint?->cleanupAfterFailure(); throw $t; }      // NEW
    if (!$updated) { $savepoint?->undoOrFail(); throw $rollbackResult; }        // NEW; owned path: runner rolls back as today
    $savepoint?->releaseOrUndo();                                               // NEW
    return true;
});
```

On the owned path `$savepoint` is `null`, so no new statement and no new branch executes there.

## A3. Reusing the existing savepoint machinery

Goal: no behavior change in the existing runners, and no unnecessary duplicated logic. Measured options (`matrix-reuse.txt` is option R1):

| | Option | Evidence / verdict |
|---|---|---|
| R1 | Run the mutation window **inside the existing `PdoDeadlockRetrySavepointTransactionRunner`** (guard as the callback's first line, a sentinel for `false`) | Identical to the inline design in all other cells, with **two gaps**: (1) **S5** — the runner swallows cleanup failures to protect the callback's `Throwable`, and here that `Throwable` is the internal "no row" sentinel, so a failed undo yields `false` **over partial shifts**; (2) **C15** — the runner re-checks `inTransaction()` itself, and after the first successful statement refreshed the driver flag it takes its **owned** path: it begins (and later rolls back) a transaction inside a call that was supposed to *participate* (`Δcommit/rollback = 0/1`), instead of failing closed. Rejected |
| R2 | Runner for exceptions + ordering-local code for `false` | Still two mechanisms for one window, still exposed to gap (2). Rejected |
| **R3** | **Extract a small `@internal` helper used only by the ordering manager** (`OperationSavepoint`: `begin()` = name + fail-closed `SAVEPOINT` + dead-transaction guard; `cleanupAfterFailure()`; `undoOrFail()`; `releaseOrUndo()`), reusing the ADR 0003 conventions verbatim (name format, `exec() === false` → `TransactionExecutionException`, original `Throwable` wins, best-effort cleanup order) | **Recommended.** ~40 lines, no public surface, and the semantics that the runners cannot provide (fail-closed `false`, no ownership switch, guard before mutation) |
| R4 | Also refactor the two runners onto the helper | **Not in this change.** It touches the runners' source and would have to prove they are unchanged; today they already duplicate ~40 lines between them. A separate, optional follow-up whose acceptance test is their existing parity and unit tests staying green untouched |

Guarding the sharing: the implementation PR leaves `src/Pdo/Transaction/**` untouched (so runner behavior cannot change), and adds one unit test that replays the same scripted `exec()` scenarios (success, callback failure, release failure, cleanup failure, `false` results) through the helper and through `PdoDeadlockRetrySavepointTransactionRunner` using the existing `SavepointTransactionPdo` double, asserting the identical statement sequence and the same exception precedence, plus the savepoint-name format. The only intended differences, covered by their own tests, are the three the ordering window needs: fail-closed `false`, no owned-path switch, dead-transaction guard.

## A4. Updated compatibility matrix (selected design)

| Contract surface | `v1.4.0` (published) | `main` | After the fix |
|---|---|---|---|
| `moveWithinScope()` signature / return type | as stated | identical | **unchanged** (`OrderingPublicApiRegressionTest` still green) |
| Return values on success, no-op, missing row | `true` / `true` / `false` | identical | **unchanged** |
| `false` after the shifts, caller-owned | partial shifts remain | same | shifts undone, `false`; undo failure → `TransactionExecutionException` |
| Throwable after the first shift, caller-owned | partial shifts remain | same | shifts undone; **original** `Throwable` rethrown |
| `PDOException` / package exception types | propagate unchanged | same | unchanged; `TransactionExecutionException` (existing public class) only for savepoint-control failures and the dead-transaction guard |
| Caller's transaction ownership | no begin/commit/rollback | same | unchanged (`0/0` everywhere); caller's own savepoints still usable (C14) |
| Package-owned path | begin/commit/full rollback | same | **unchanged, byte for byte** |
| Earlier operations of the same caller transaction | kept | kept | kept (C13) |
| SQL statement stream, participating path | none extra | none extra | **+2** (`SAVEPOINT`, `RELEASE`) per **mutating** move; **0** for no-op / missing row; on failure +`ROLLBACK TO` |
| Lock behavior | scope locked until the owner ends | same | **same** (savepoint placed after the lock) |
| `1213`, MariaDB `1020`, `1205`+`rollback_on_timeout=ON` | original exception, transaction lost | same | same; cleanup attempt fails harmlessly; not rescued, not retried |
| Swallowed `1213` then call again | silent autocommit of partial shifts | same | fails closed before mutating |
| `getNextPosition()`, `getNextPositionForUpdate()`, `rowExistsInScope()` | | | untouched |
| `PdoTransactionRunner`, `PdoSavepointTransactionRunner`, new runners, `OwnedTransactionExecutor` | | | untouched (no source change) |
| Pinned test(s) | `testParticipantFailure…` asserts partial state | identical file | that **one** test changes (measured on the final prototype: 619/620 pass, 1 fails, only it) |
| Public API regression test | | | unchanged (no method added) |
| Observable by existing callers | | | only failure paths (state now clean instead of corrupt) and the 2 extra control statements on mutating moves |

## A5. What must be recorded before implementation

* **Contract classification (honest).** This is a **published failure-path behavior change** to a method whose previous behavior was pinned by a published test, although its success path, signature, ownership and savepoint semantics are preserved. It must not be described as a free compatibility fix. AGENTS.md requires the v1.4.0 public contract to be preserved; this change deliberately amends one failure-path state of it and therefore needs the Owner's **explicit written consent** (a decision record on PR #54/Issue #48) before Runtime work starts.
* **Release.** `CHANGELOG [Unreleased]` already carries `Added` and `Deprecated` entries, so the next release is a MINOR regardless (proposed `1.5.0`); this adds a `Changed` entry that names the behavior change, the removed partial-state outcome, the +2 statements and what is unchanged. No tag or release is created by this work, and none by the implementation PR unless the Owner instructs.
* **ADR.** A new ADR (next number; ADR 0003's decisions stay in force) recording: the decision, the lazy placement and why (L1/L2), why not an opt-in method, why not wrap the runners (R1 gaps), and the not-rescuable list.

## A6. Tests the implementation must bring (RED first)

1. **Replace the old-behavior test.** `ScopedOrderingManagerTransactionTest::testParticipantFailureLeavesOuterTransactionActiveWithoutIndependentRollback` (line 100; same at `v1.4.0`) is the test that proves the old behavior. Step 1 of the implementation PR rewrites its expectation to the new contract (rows equal `$before` inside the still-active transaction, the original `45000` exception, outer rollback restores the same) and shows it **failing** on the current code; step 2 implements. The old assertion is kept as history in the ADR and in this study (`results/matrix-real-v1.4.0.txt`).
2. **Real-MySQL 8.4.10 integration tests**, one per cell: C04 (trigger), C06 (`UNIQUE (updated_at)` configuration), C07 (lock partner + `innodb_lock_wait_timeout`), C13, C14, nested savepoint runner, C10/C15 with the existing `DeadlockPartner` infrastructure, C02/C03 asserting **no** savepoint statement (`Com_savepoint`), and the package-owned controls O1–O3. `1020`, `rollback_on_timeout=ON` and MariaDB cells stay out of CI (MariaDB is not a CI target — Issue #51; the server option needs a second service) and are documented as study evidence.
3. **Unit tests** (scripted `PDO` double, the `SavepointTransactionPdo` style) for S1–S7 and the dead-transaction guard, and the runner/helper parity test of §A3.
4. **Regression:** the public-API test stays as is; a documentation regression test pins the new wording.
5. Quality gates as documented in `CONTRIBUTING.md` (PHPStan max, php-cs-fixer, unit/regression/integration twice, residue check, consumer harness) before the implementation PR leaves Draft.

## A7. Documentation to change with the implementation

| File | Lines (at `main@01f59d6`) | Change |
|---|---|---|
| `src/Pdo/Ordering/ScopedOrderingManager.php` | class DocBlock and `moveWithinScope()` DocBlock | transaction model; savepoint after the scope lock; failure semantics; not-rescuable list |
| `PERSISTENCE_PACKAGE_REFERENCE.md` | 136–137, 478, "Rollback Ownership" table row 2 and the paragraph at 517–519 | participating call undoes its own mutation window; caller still owns the outcome |
| `README.md` | 260–270 (including "No savepoint guarantee in the ordering API"), 507–508 | same |
| `docs/guides/MOVE_WITHIN_SCOPE_HOW_TO_USE.md` | 95–99, 121–135 | the `false`/throw guidance; ownership section |
| `examples/move-within-scope/01_move_after_older_snapshot.php` | 23–24 | comment on partial work |
| `CHANGELOG.md` | `[Unreleased]` | `Changed` entry (§A5) |
| `docs/adr/` | new ADR + index | §A5 |
| `CONTRIBUTING.md` | "Transaction Ownership" bullet | unchanged text stays true; add the savepoint sentence |

## A8. Still pending and untested

* **Pending:** the Owner's explicit consent to the published failure-path change (§A5); the implementation PR with independently reviewed Runtime, tests and docs.
* **Residuals kept from the report:** a failed undo after a real failure leaves the partial state and the original exception (S3/S4); non-transactional side effects are not undone; two extra control statements per mutating move (no latency measurement).
* **Note:** the PHPUnit assertion total varies by a few between runs of the same code (4421 and 4430 observed; loop-based concurrency tests); the test count (620) and the verdict do not.
* **Untested:** `READ COMMITTED`/`SERIALIZABLE` runs of the matrix, other MariaDB versions, MyISAM/mixed engines, XA, proxy or cluster layers, emulated prepares, PHP versions other than 8.4.4.
