# Issue #48 — partial shifts in `moveWithinScope()` inside a caller-owned transaction: operation-local savepoint study

> **Superseded in part by [Addendum 1](ADDENDUM-1-FINAL-DESIGN.md)** (Lead decision on PR #54): the recommended design is now the fix of the existing `moveWithinScope()` with a lazy savepoint placed after the scope lock, **not** an opt-in method. §8, §11-Q1 and §11-Q2 below are historical; the evidence in §§1–7 and 9 stands. The Addendum also shows that the partial-shift behavior is in the published `v1.4.0`.

**Status:** evidence + one recommended design. **No Runtime, Public API, test, CI, composer or public-doc file is changed.** Owner approval is required before any implementation (§10). Nothing here is released, tagged or merged.
**Scope guard:** Issue #47 (MariaDB `1020`) and Issue #51 (MariaDB integration-suite gaps) are taken as given and not re-analysed; `1020` appears only as an error class the design must not claim to rescue.

## 1. Exact base, head, tree

| | |
|---|---|
| Base (`origin/main`, after PR #53) | `01f59d6d21849a02e91eee051f548f16b9661633` |
| Base tree | `31042c617616cc43ccc6e8b500600cfc3c0b551c` |
| Study head / tree | recorded in the Draft PR description (a commit cannot contain its own hash). The diff against the base is **only** `docs/studies/issue-48-partial-shifts/` |
| Files outside `docs/studies/issue-48-partial-shifts/` | none |

Environment ([`results/env.txt`](results/env.txt)): macOS/arm64, Docker 29.8.2, PHP 8.4.4 (`pdo_mysql`, mysqlnd), `ATTR_EMULATE_PREPARES=false`, `ERRMODE_EXCEPTION`.

| Engine | Image digest | Role |
|---|---|---|
| MySQL 8.4.10 | `sha256:8dbcf531…7db6` | the repository CI engine (primary verification target) |
| MySQL 8.4.10 + `innodb_rollback_on_timeout=ON` | same image | server option that changes what `1205` does |
| MySQL 8.0.46 | `sha256:7dcddc01…ae2b` | consistency check |
| MariaDB 11.8.9 (`innodb_snapshot_isolation=ON`, its default) | `sha256:f16d7a34…b34fa` | supplementary only: provides the `1020` cells. MariaDB is **not** a CI verification target of this repository |

Existing suite, unmodified base, MySQL 8.4.10: `vendor/bin/phpunit` → **OK (620 tests, 4421 assertions)** ([`results/phpunit-existing-suite.txt`](results/phpunit-existing-suite.txt)). The integration tests need `log_bin_trust_function_creators=1`, which the CI sets in its "Configure MySQL trigger creation policy" step; the study containers set it the same way.

## 2. Result in one paragraph

The defect is real, reproducible on the unmodified package, and independent of the engine: when `moveWithinScope()` **participates** in a caller-owned transaction it runs the range shift and the target write as separate statements with no undo of its own, so any failure *between* them (or a `false` returned by the target write) leaves rows shifted — duplicated and missing positions (`1:1 2:3 3:4 4:5 5:5`, 'CORRUPT' in the results) — inside the caller's transaction, and a caller that carries on and commits makes that permanent. An **operation-local savepoint** fixes every case in which the transaction is still alive (`false`, `1062`, `1205` with the default server option, injected control-statement failures) without touching the caller's transaction, the caller's own savepoints, the package-owned path, or any earlier operation of the same transaction. It does **not** and cannot rescue `1213`, MariaDB `1020`, or `1205` under `innodb_rollback_on_timeout=ON`: the server has already ended the transaction, the savepoint is gone with it, and the design simply propagates the original exception. The decisive finding for the contract question: a savepoint **created before the scope lock** (what a consumer gets by wrapping the call in a savepoint runner) makes a failed move *release the scope locks*, because `ROLLBACK TO SAVEPOINT` releases locks taken after the savepoint on every tested engine; a savepoint **created lazily right before the first mutating statement** keeps the v1.4.0 lock behavior. Making the savepoint the default behavior of `moveWithinScope()` changes a documented, tested v1.4.0 behavior (1 of 620 existing tests, measured, §7). The recommended design is therefore an **additive, opt-in method** with the lazy savepoint window (§8).

## 3. What was run

Every cell: the caller begins a transaction, writes a *marker row* (earlier caller work), calls the subject, then "carries on" and commits whatever the transaction holds; a second connection reports what is committed. Subjects:

| Subject | What it is |
|---|---|
| `real` | the shipped `ScopedOrderingManager::moveWithinScope()`, unmodified |
| `recipe` | no Runtime change: `PdoDeadlockRetrySavepointTransactionRunner` around the shipped call, throwing inside the callback on `false` |
| `proto` | [`SavepointMovePrototype.php`](SavepointMovePrototype.php): the recommended design as a standalone study class (not under `src/`, not autoloaded) |
| `nested` | `proto` called inside a consumer's `PdoDeadlockRetrySavepointTransactionRunner` |

Fixture: scope 1 holds ids 1–5 at positions 1–5, scope 2 two more rows, plus an audit table (trigger target / lock partner), a marker table and a filler table. The move is id 5 → position 2 (shifts ids 2–4, then writes id 5). Expected result `1:1 2:3 3:4 4:5 5:2` ('MOVED'); untouched is 'ORIGINAL'.

How each failure is produced (real engine behavior, not mocks, except where marked **injected**):

| Cell | Mechanism |
|---|---|
| C04 `false` after shifts | `BEFORE UPDATE` trigger turns the target write into a no-op (`rowCount() == 0`) |
| C05 `1062` in the shift | `UNIQUE (scope, order)` — the documented incompatible schema |
| C06 `1062` on the target write | `UNIQUE (updated_at)`; the move supplies a colliding timestamp (plain schema, valid `ScopedOrderingConfig`) |
| C07/C08 `1205` | `AFTER UPDATE` trigger touches an audit row locked by another transaction; `innodb_lock_wait_timeout=1`; C07 at the target write (after the shifts), C08 at the first shifted row |
| C10 `1213` | a real deadlock: the caller holds audit row 1, the trigger needs audit row 2, a child process holding row 2 (heavier transaction) then requests row 1; the caller is the victim |
| C11/C12 `1020` | MariaDB `ON`: a row changed by another connection after the caller's snapshot is visited by the scope lock (C11) or by the trigger at the target write (C12). On MySQL the same scenario is a control that succeeds |
| C13 / C14 | operation-locality: a second move fails after a first succeeded / the caller has its own savepoint |
| C15 | the forbidden pattern: swallow the `1213`, call again |
| S1–S7 | savepoint **control statements** failing, **injected** through a `PDO` subclass (`FaultyPdo`; the same idea as the repository's scripted `SavepointTransactionPdo` unit-test double, but over a live connection): `SAVEPOINT` false/throws, `ROLLBACK TO` false/throws after a real failure, `RELEASE` false/throws |
| O1–O3 | package-owned (no caller transaction) controls |

## 4. RED — the shipped call (`matrix-real.txt`)

`afterCommit` is what another connection sees after the caller commits. Identical on MySQL 8.0.46, 8.4.10 and MariaDB 11.8.9 unless noted.

| Cell | Result | Caller's view / transaction | After the caller's commit |
|---|---|---|---|
| C03 missing row | `false` | untouched | ORIGINAL (no shift happened — `false` *before* the shifts is safe) |
| **C04 `false` after shifts** | `false` | **`1:1 2:3 3:4 4:5 5:5`**, transaction alive | **PARTIAL, permanent** |
| C05 `1062` in the shift | `1062` | ORIGINAL (the failing statement is rolled back whole) | ORIGINAL |
| **C06 `1062` on the target write** | `1062` | **`1:1 2:3 3:4 4:5 5:5`**, alive | **PARTIAL** |
| **C07 `1205` on the target write** | `1205` | **PARTIAL**, alive (default server option) | **PARTIAL** |
| C08 `1205` in the shift | `1205` | ORIGINAL, alive | ORIGINAL |
| C07/C08 with `rollback_on_timeout=ON` | `1205` | **whole transaction lost**, marker gone | ORIGINAL, marker lost |
| C10 `1213` | `1213` | **whole transaction lost**, marker gone | ORIGINAL, marker lost; the later `commit()` throws |
| C11/C12 `1020` (MariaDB) | `1020` | **whole transaction lost** | ORIGINAL, marker lost |
| **C13 two moves** | `true`, `1062` | 2nd leaves PARTIAL on top of the 1st | **PARTIAL** (`1:1 2:2 3:3 4:5 5:1`) |
| **C15 carry on after `1213`** | `1213`, `1062` | later statements autocommit | **PARTIAL, committed immediately** |

Reading: partial state arises exactly when the failure falls **between the first shift and the end of the target write** *and the transaction survives*. When the engine ends the transaction (`1213`, `1020`, `1205` with the server option), the partial state is gone with it but so is all of the caller's earlier work, and nothing in the package can change that.

Honest reachability: with a plain schema, `false` after the shifts needs a target write that changes no row (a trigger, as here) and `1062`/`1205` on the target write need another unique key, a trigger touching a contended table, or similar. The ordinary single-statement failure modes (`C05`, `C08`, lock timeout while waiting for the scope) are already safe. The hazard is concentrated in callers that **catch and continue** — the repository's own guidance says to catch only "expected error codes (`1062`, `1205`, package exceptions)" around a savepoint runner — and in callers that treat `false` as a soft "not found". A caller that lets the exception propagate and rolls back is correct today.

## 5. GREEN — the recommended design (`matrix-proto.txt`, `matrix-nested.txt`)

Savepoint statement counts are `Δsp/rt/rel` = `SAVEPOINT` / `ROLLBACK TO SAVEPOINT` / `RELEASE SAVEPOINT` issued by the call. `Δcommit/rollback` was `0/0` in **every** caller-owned cell of the prototype (it never commits or fully rolls back the caller's transaction).

| Cell | Prototype result | State / transaction | Statements |
|---|---|---|---|
| C01 success | `true` | MOVED, alive | 1/0/1 |
| C02 no-op, C03 missing | `true`, `false` | unchanged | **0/0/0** (savepoint is created only before the first mutating statement) |
| **C04 `false` after shifts** | `false` | **ORIGINAL**, marker kept, alive | 1/1/1 |
| C05 / **C06** `1062` | original `PDOException` | **ORIGINAL**, alive | 1/1/1 |
| **C07 / C08** `1205` (default option) | original `PDOException` | **ORIGINAL**, alive | 1/1/1 |
| **C13** | `true`, then original `1062` | 1st move **kept**, only the failing 2nd undone | 2/1/2 |
| **C14** | original `1062` | ORIGINAL; the caller's own savepoint can still be rolled back to | 1/1/1 |
| nested (`nested`) | same results | same states | 2/·/2 — both savepoints cleaned up |
| **C15** carry on after `1213` | `1213`, then `TransactionExecutionException` | ORIGINAL, nothing autocommitted | fails before the first mutation |
| O1–O3 package-owned | identical to the shipped call | | 0/0/0, owned `COMMIT`/`ROLLBACK` as before |

**Error classes that are not rescued** (the prototype *attempts* `ROLLBACK TO SAVEPOINT`, which fails because the savepoint no longer exists, swallows that, and rethrows the original; no error code is special-cased):

| Error | Transaction after it | Prototype |
|---|---|---|
| `1213` (C10) | lost; savepoint gone | original `1213` propagates; `1/1/0` |
| `1020` MariaDB, scope-lock phase (C11) | lost | original `1020`; **no savepoint statement issued** (lazy window) |
| `1020` MariaDB, target-write phase (C12) | lost | original `1020`; `1/1/0` |
| `1205` with `rollback_on_timeout=ON` (C07) | lost | original `1205`; `1/1/0` |

On MySQL the `1020` cells are controls: the engine performs the current read and the move succeeds.

**Savepoint command failures** (injected; the statement is not executed):

| Cell | Result | Notes |
|---|---|---|
| S1 `SAVEPOINT` returns `false` | `TransactionExecutionException`; ORIGINAL | nothing mutated |
| S2 `SAVEPOINT` throws | that exact `Throwable` object; ORIGINAL | nothing mutated |
| S3 `1062` then `ROLLBACK TO` returns `false` | the original `1062` wins | **residual:** shifts remain; the caller must roll back (same as today) |
| S4 original `RuntimeException`, `ROLLBACK TO` throws | the original object wins | same residual |
| **S5 `false` outcome, `ROLLBACK TO` fails** | **`TransactionExecutionException`, never `false`** | `false` promises "nothing changed"; with no original `Throwable` to protect, a failed undo must surface. (`recipe` reports `false` here over PARTIAL shifts — the gap a consumer composition cannot close) |
| S6 `RELEASE` returns `false` | `TransactionExecutionException`; the move is undone | ADR 0003 release-failure semantics |
| S7 `RELEASE` throws | that exact `Throwable`; undone | |

**Differential parity** ([`results/s4-differential.txt`](results/s4-differential.txt)): 400 seeded random operations per shape (valid, clamped, no-op, missing, soft-deleted, other-scope, global and scoped), caller-owned and package-owned, on twin tables, four engines → **0 mismatches** in return values and full table state; the package-owned path issued 0 savepoint statements.

**Locks** ([`results/s5-locks.txt`](results/s5-locks.txt), all four engines): (L1) a row lock taken after `SAVEPOINT` is **released** by `ROLLBACK TO SAVEPOINT`; therefore (L2) after a failed move the scope stays locked for `real` and `proto` (the scope lock precedes the savepoint) but is **free** for `recipe` (savepoint first); (L3) after a successful move the scope is locked for all three. The v1.4.0 lock contract ("held until the owning transaction ends") is preserved only by the lazy window.

All of this is asserted by [`assert-results.sh`](assert-results.sh): **190 checks, 0 failures**, and two negative controls (tampered results) fail as they should.

## 6. Error taxonomy used by the design

| Class | Rescuable by an operation-local savepoint? | Why | Design behavior |
|---|---|---|---|
| `false` after the shifts | **yes** | transaction alive | `ROLLBACK TO` must succeed, else throw (fail closed); then return `false` |
| `1062` | yes | statement-level rollback, transaction alive | undo, rethrow original |
| `1205`, default `innodb_rollback_on_timeout=OFF` | yes | statement-level, transaction alive | undo, rethrow original |
| `1205`, `rollback_on_timeout=ON` | **no** | whole transaction rolled back (C07/C08 `rot`) | cleanup fails harmlessly, rethrow original |
| `1213` | **no** | victim's whole transaction rolled back | same; never retried, never swallowed |
| MariaDB `1020` | **no** | whole transaction rolled back (Issue #47) | same |
| `SAVEPOINT` / `ROLLBACK TO` / `RELEASE` failing | n/a | control failure | `TransactionExecutionException` for non-throwing `false`; thrown failures propagate; original `Throwable` always wins over cleanup failures |

The design never classifies by error code: the engine decides whether the savepoint still exists, and the design only ever attempts the undo and reports the original failure. Not claiming `1213`/`1020` as rescuable is therefore a property of the structure, not of a list that could go stale.

## 7. Public contract impact matrix

Columns: **A** status quo (documentation only); **B** consumer-side recipe (savepoint runner around the call; documentation only); **C** always-on lazy savepoint inside `moveWithinScope()`; **D** *recommended*: additive opt-in method with the lazy window; **E** flag or extra parameter on the existing API.

| Contract surface (v1.4.0) | A | B | C | **D** | E |
|---|---|---|---|---|---|
| Existing method signatures (pinned by `OrderingPublicApiRegressionTest`) | unchanged | unchanged | unchanged | unchanged (one method **added**) | **changed** (trailing parameter, or `ScopedOrderingConfig` constructor — barred by the Owner's class-versioning policy, ADR 0004) |
| Return values `true` / `true` (already there) / `false` | unchanged | unchanged | unchanged, but `false` now also *means* "undone" | unchanged for `moveWithinScope()` | unchanged |
| Failure-path state inside a caller-owned transaction (documented: Package Reference "Rollback Ownership", How-To, README; **pinned by** `ScopedOrderingManagerTransactionTest::testParticipantFailureLeavesOuterTransactionActiveWithoutIndependentRollback`) | partial shifts remain | consumer undoes | **changes** — measured: with C routed in, the existing suite fails **exactly that 1 of 620 tests** | unchanged for `moveWithinScope()`; the new method undoes | changes only when opted in |
| Exceptions: type and propagation | unchanged | unchanged | `PDOException` unchanged; `TransactionExecutionException` (existing public class) added for control failures and the dead-transaction guard | same, only on the new method | same |
| Ownership of the caller's transaction | no begin/commit/rollback | same | same (`Δcommit/rollback=0/0` everywhere) | same | same |
| Package-owned path | unchanged | unchanged | unchanged (delegated) | unchanged | unchanged |
| SQL statement stream | none extra | +2 on **every** call incl. no-op/missing | +2 only on a mutating participating move | +2 only on a mutating participating move, only for opted-in callers | as C for opted-in callers |
| Lock contract after a failed move | scope stays locked | **scope released** (eager savepoint, L2) | scope stays locked (lazy) | scope stays locked | as C |
| Caller's own savepoints / nesting | n/a | works | works (C14, nested) | works | works |
| `1213` / `1020` / `1205`+rot | propagate | propagate (cleanup fails harmlessly) | propagate | propagate | propagate |
| `false` + failed undo | `false` over partial state | **`false` over partial state** (S5) | fails closed | fails closed | fails closed |
| Carry-on after a swallowed `1213` | silent autocommit of partial shifts | clean, but by side effect: the runner's `SAVEPOINT` reply refreshes the driver flag and the shipped call then opens its own transaction (C15, all four engines) | fails closed | fails closed | fails closed |
| Existing tests changed | 0 | 0 | **1** (measured) | 0 | 1+ (signature pin) |
| Docs that must change | none | add a recipe | Package Reference "Rollback Ownership" + Deadlock/`1020` sections, How-To, README, DocBlock, CHANGELOG, a new ADR | add the method to the same places + ADR | as C |
| SemVer reading | none | none | behavior change of a documented, tested behavior: Owner decision, not a silent patch | additive MINOR | signature/behavior change |
| Fixes the reported defect for | nobody | consumers who adopt the recipe | every participating caller | callers who opt in | callers who opt in |

Measurement behind column C: the unmodified base passes 620/620; the same suite with `moveWithinScope()` routed to the prototype whenever a transaction is active ([`results/d1-adapter.diff`](results/d1-adapter.diff)) passes 619/620 and fails only the test above ([`results/phpunit-existing-suite.txt`](results/phpunit-existing-suite.txt)). Column D changes no existing test by construction. `getNextPosition()`, `getNextPositionForUpdate()` and `rowExistsInScope()` are untouched in every column.

## 8. Recommended design (one) — SUPERSEDED by Addendum 1 (kept as the first-round recommendation)

**An additive, opt-in method on `ScopedOrderingManager` — working name `moveWithinScopeWithSavepoint()` (the name is an Owner question) — with the same parameters and return type as `moveWithinScope()`, which is left unchanged.**

Behavior (this is what `SavepointMovePrototype` models):

1. **No active transaction:** delegate to `moveWithinScope()` unchanged (package-owned begin/commit/rollback, `false` rolls back).
2. **Active caller-owned transaction:** validate arguments; run the **read-only phase outside any savepoint** (scope lock, target lookup, clamp, no-op check). A missing row or a no-op returns `false`/`true` with **no savepoint statement**.
3. Immediately before the **first mutating statement** issue `SAVEPOINT maatify_persistence_sp_<32 hex>` (the ADR 0003 naming contract); a non-throwing `false` becomes `TransactionExecutionException`; a thrown failure propagates unchanged. If the driver then reports no active transaction, throw `TransactionExecutionException` before mutating (the dead-transaction guard; closes the carry-on-after-`1213` hole, C15).
4. Run the range shift and the target write.
5. **Success:** `RELEASE SAVEPOINT`. If it fails: best-effort `ROLLBACK TO` + `RELEASE`, then throw the release failure (ADR 0003).
6. **Any `Throwable` in step 4:** best-effort `ROLLBACK TO` + `RELEASE`, **rethrow the original unchanged**; cleanup failures never replace it.
7. **Target write reports no row (`false`):** `ROLLBACK TO` **must** succeed or its `Throwable` propagates (fail closed); then best-effort `RELEASE`; return `false`.
8. Never commit, fully roll back, retry or re-begin the caller's transaction; no error-code special-casing; `1213`, `1020` and `1205`+`rollback_on_timeout=ON` surface as their original exceptions and are documented as not rescuable.

Why this and not the others:

* It is the only option that **fixes the defect for opted-in callers while changing nothing for existing ones**: no pinned signature, no pinned behavior, no documented statement becomes false (column D above).
* The **lazy window** is what keeps the v1.4.0 lock behavior, issues no extra statement for no-op/missing calls, and keeps `1020` in the scope-lock phase free of any savepoint statement.
* It improves on the consumer recipe (B) on exactly the points a consumer cannot fix: eager savepoint cost, lock release on failure, and `false` over partial state when the undo fails (S5).
* Option C is attractive (it fixes everyone) but is a behavior change to a documented and tested contract; it is not recommended without an explicit Owner decision to amend that contract. If the Owner prefers C, the same prototype is the implementation and exactly 1 test and the documented rows in §7 change.

## 9. Limits, residuals and untested cells

* **Residuals the design cannot remove:** S3/S4 — if the undo itself fails after a real failure, the partial shifts remain and the original exception is thrown; the caller must roll back (unchanged from today). Effects outside the connection's transaction (triggers writing to non-transactional tables, external side effects) are not undone by `ROLLBACK TO SAVEPOINT`. A trigger that touches other transactional tables *is* undone together with the move (C07).
* **Savepoint cost:** two extra statements per mutating participating move (measured as `Δsp/rel`); no latency measurement was made, no performance claim is made.
* **`false`** after the shifts needs a target write that changes no row; it is rare with plain schemas. The more common reachable cases are `1062`/`1205`/trigger errors on the target write for callers that catch and continue.
* **Tested:** MySQL 8.0.46 and 8.4.10; MySQL 8.4.10 with `innodb_rollback_on_timeout=ON`; MariaDB 11.8.9 with the `1020` default; `REPEATABLE READ`; InnoDB; PHP 8.4.4/mysqlnd; native prepares; transactions begun with `PDO::beginTransaction()`; single host.
* **Untested:** MariaDB other than 11.8.9 (it remains a supplementary engine, not a CI target — Issue #51); `READ COMMITTED`/`SERIALIZABLE` runs of the matrix; MyISAM or mixed engines; XA/`START TRANSACTION WITH CONSISTENT SNAPSHOT`; Galera, ProxySQL, PlanetScale-style layers (savepoint pass-through unverified); emulated prepares; PHP versions other than 8.4.4 (the package supports `>=8.2`).
* The `FaultyPdo` cells (S1–S7) are **injected** control-statement failures, labeled as such; the corresponding real-server failures (`ROLLBACK TO` after transaction loss) are covered by C07rot/C10/C12.
* The study scripts are probes: not autoloaded, not analysed or formatted by the repository gates.

## 10. Proposed work after approval (nothing added now)

1. Implementation of §8 as a private shared body plus the one new public method; `moveWithinScope()` untouched.
2. PHPUnit integration tests, MySQL 8.4.10, one per RED cell now documented as partial (C04, C06, C07, C13), plus C05, C08, C10, C14, C15 and the package-owned controls; unit tests for S1–S7 with a `PDO` double in the style of `SavepointTransactionPdo`; the public-API regression test extended with the new method; a documentation regression test.
3. Documentation (Package Reference "Rollback Ownership" and the `1213`/`1020` sections, the How-To, README, DocBlock, CHANGELOG) and an ADR; the "no savepoint-rollback guarantee" sentences keep describing `moveWithinScope()`.
4. Version as MINOR (additive) only on Owner instruction; no tag or release from this work.

## 11. Questions for the Owner

1. **Q1 — opt-in or default.** Is the recommended opt-in method (D) acceptable, or should the documented participating behavior itself be amended (C, changing 1 pinned test and several documented rows)?
2. **Q2 — shape and name.** A new method on the `final readonly` `ScopedOrderingManager` (additive), or a new class generation as in ADR 0004? Name?
3. **Q3 — the dead-transaction guard (§8 step 3).** Keep the fail-closed `TransactionExecutionException` when the driver reports no transaction after `SAVEPOINT`, or leave detection out of scope?
4. **Q4 — `false` + failed undo.** Confirm that fail-closed (`TransactionExecutionException`, never `false` over partial state) is the intended contract.
5. **Q5 — evidence breadth.** Is MySQL 8.4.10 (CI) plus these supplementary engines enough, or should `READ COMMITTED`/`SERIALIZABLE` and more MariaDB versions be added before implementation?

## 12. Reproduce

See [`README.md`](README.md). Every number above is in `results/`.
