# Issue #47 — MariaDB snapshot isolation vs. the ordering APIs: investigation and design proposal

**Status:** evidence + proposal. **No Runtime, Public API, test, CI or public-doc file is changed.** Owner approval is required before any implementation (§9).
**Scope guard:** Issue #48 (caller-owned ordering transaction atomicity, RC-02) is unrelated and untouched. The MariaDB integration-suite failures that are *not* `1020` (engine-version pin, deadlock-victim transaction state) are tracked separately in [Issue #51](https://github.com/Maatify/persistence/issues/51); no test was changed here.

## 1. Exact base

| | |
|---|---|
| Base (`origin/main`, after PR #49) | `0685d777783a1346c0ba29029c4d191544afa1ca` |
| Base tree | `cafe279be7fcb61eaa945e6d5a0607840c29fec5` |
| Study head / tree | recorded in the Draft PR description (the head is this commit; it adds only `docs/studies/issue-47-mariadb-snapshot/`) |
| Originating evidence | PR #45 at `3b5b71f2868cd6c1451691f036473a870d135c2b` (`rc01/engine_snapshot.php`, `engine-snapshot-mariadb11.txt`) |

Host: macOS/arm64, Docker 29.8.2, PHP 8.4.4 (`pdo_mysql`, mysqlnd), `ATTR_EMULATE_PREPARES=false`.

| Engine | Image digest |
|---|---|
| MariaDB 11.8.9 (`mariadb:11`) | `sha256:6422478c…331f15` |
| MariaDB 11.4.7 | `sha256:39596f07…de85b06` |
| MariaDB 10.11.18 (`mariadb:10.11`) | `sha256:be981e41…37350` |
| MySQL 8.4.10 (the repository CI container) | `sha256:8dbcf531…7db6` |
| MySQL 8.0.46 | `sha256:7dcddc01…ae2b` |

## 2. Result in one paragraph

The failure is real, reproducible on the unmodified package, and is **not a MariaDB-11.8 version effect but an engine *setting* effect**: with `innodb_snapshot_isolation=ON`, MariaDB refuses the "current read" that both APIs are *defined* to perform (read the latest committed rows with a locking statement inside an older `REPEATABLE READ` snapshot, Issue #33) and raises `1020`. MariaDB 10.11.18 and 11.4.7 (default `OFF`) reproduce identically once the setting is `ON`, and 11.8.9 behaves like MySQL once it is `OFF`. The shipped `v1.4.0` `moveWithinScope()` is affected too. New finding beyond the issue: `1020` makes the **server abort the caller's whole transaction** while `PDO::inTransaction()` still reports it active, so a caller who swallows the error and continues silently loses atomicity (version-dependent shape, §5). The guarantee cannot be restored from inside the package without either changing the host's configuration or silently overriding it; the recommendation (§7) is to **keep Runtime unchanged, make the engine-conditional contract explicit, and put the remedy with the consumer** (the fail-closed wording is limited to exception propagation, §7).

## 3. RED reproduction (current base, real engines)

`p1_reproduce.php` — outer transaction, plain read (snapshot), another connection commits two rows, then the shipped call. Full output: [`results/p1-reproduce.txt`](results/p1-reproduce.txt).

| Engine | `innodb_snapshot_isolation` | `getNextPositionForUpdate()` | `moveWithinScope()` | package-**owned** `moveWithinScope()` (control) |
|---|---|---|---|---|
| MySQL 8.0.46 / 8.4.10 | absent | 6 | `true`, order 5,1,2,3,4 | `true` |
| MariaDB 10.11.18 | `0` (default) | 6 | `true` | `true` |
| MariaDB 10.11.18 | `1` (session) | **1020** | **1020** | `true` |
| MariaDB 11.4.7 | `0` (default) | 6 | `true` | `true` |
| MariaDB 11.4.7 | `1` (session) | **1020** | **1020** | `true` |
| MariaDB 11.8.9 | `1` (default) | **1020** | **1020** | `true` |
| MariaDB 11.8.9 | `0` (session) | 6 | `true`, same rows as MySQL | `true` |

Released tag `v1.4.0` ([`results/p1-reproduce-v1.4.0.txt`](results/p1-reproduce-v1.4.0.txt)): `getNextPositionForUpdate()` does not exist there; `moveWithinScope()` raises `1020` on MariaDB 11.8.9 default and passes everywhere else in the table. So the defect is not limited to the unreleased code.

Repository integration suite (`vendor/bin/phpunit --testsuite integration`, unchanged code, [`results/phpunit-*.txt`](results)):

| Engine / setting | Result |
|---|---|
| MySQL 8.4.10 | OK (177 tests) |
| MariaDB 11.8.9, default (`ON`) | 25 tests fail with `1020`: 13 in `ScopedOrderingManagerMoveStaleSnapshotTest`, 10 in `ScopedOrderingManagerStaleSnapshotTest`, 2 examples (`01_move_after_older_snapshot`, `03_stale_snapshot_vs_plain_read`). 2 further errors are the engine-version pin `assertMySql84` (Issue #51, not analysed) |
| MariaDB 11.8.9, `SET GLOBAL innodb_snapshot_isolation=OFF` | **0** tests with `1020` (only the 2 Issue #51 pin errors remain) |
| MariaDB 11.4.7 and 10.11.18, `SET GLOBAL … =ON` | the same 25 `1020` failures (11.4.7 additionally shows 3 deadlock-victim transaction-state failures independent of this setting, Issue #51; logs: `results/phpunit-mdb*-snapshot-OFF-non47-failures.txt`) |

That is the RED-then-GREEN-under-documented-conditions comparison: the only variable that flips the 25 tests is the engine setting.

## 4. Root cause

`p2_root_cause.php` ([`results/p2-root-cause.txt`](results/p2-root-cause.txt)) runs each statement shape as the first statement after a stale snapshot, under each kind of concurrent commit. On MariaDB 11.8.9 default:

| Statement after the stale snapshot | other INSERT in scope | other UPDATE of a scope row | other soft-DELETE | other INSERT, *other scope* | no change |
|---|---|---|---|---|---|
| `SELECT … ORDER BY order DESC LIMIT 1 FOR UPDATE` (`getNextPositionForUpdate()`) | **1020** | **1020** | **1020** | ok | ok |
| `SELECT … ORDER BY order, id FOR UPDATE` (`moveWithinScope()` scope lock) | **1020** | **1020** | **1020** | ok | ok |
| `… FOR UPDATE NOWAIT` / `SKIP LOCKED` | 1020 | 1020 | 1020 | ok | ok |
| `UPDATE … SET order = order + 1 WHERE <range>` (the shift) | ok¹ | **1020** | **1020** | ok | ok |
| `SELECT … FOR UPDATE` of an untouched row | ok | ok | ok | ok | ok |
| plain `INSERT` / non-locking `SELECT MAX` | ok | ok | ok | ok | ok |

¹ the inserted row lies outside the shifted range. MySQL 8.4.10 returns `ok` in every cell (it performs the current read).

Mechanism, from observed behaviour: under `REPEATABLE READ` (and `SERIALIZABLE`) the transaction has a read view from its first non-locking read. A locking read or `UPDATE`/`DELETE` must act on the *latest* committed version. When that version is not visible in the read view, MySQL (and MariaDB with the setting `OFF`) proceeds with the latest version; MariaDB with `innodb_snapshot_isolation=ON` implements true snapshot isolation and raises `ER_CHECKREAD` (`1020`, SQLSTATE `HY000`) instead. The error occurs only when the locking statement actually visits a record that changed after the snapshot — i.e. precisely when the Issue #33 behaviour ("the clamp/next position follows the latest committed rows") would have mattered; with no concurrent change, or a change confined to another scope, nothing fails. Further observations:

* A locking read as the **first** statement creates no read view (control in `p2`): no `1020`. This is why a package-owned transaction, and a caller who takes the serialization lock before any plain read, are unaffected.
* The setting, not the version, decides: 10.11.18/11.4.7 reproduce with it `ON`.
* `MariaDB` rejects `FOR SHARE` (`1064`; it uses `LOCK IN SHARE MODE`). The package only emits `FOR UPDATE`, so this is irrelevant to Issue #47 and noted for completeness.
* Ordinary queueing concurrency (two fresh transactions) yields the already-documented gap-lock `1213`, never `1020`, on every engine (`p6`, control).

`1020` is therefore the engine honestly telling the application that the contract "locking read returns the latest committed state inside an older snapshot" is unavailable under that configuration.

## 5. New finding: `1020` aborts the caller's whole transaction

`p3_error_fate.php` ([`results/p3-error-fate.txt`](results/p3-error-fate.txt)), MariaDB with the setting `ON` (10.11.18, 11.4.7, 11.8.9):

* on all three, the transaction's **earlier write is gone and its locks are released** (a second connection can update the row at once) — the server rolled back the **entire** transaction, not only the statement;
* `PDO::inTransaction()` still returns `true` (the `pdo_mysql` flag only changes on the next successful reply — the same effect ADR 0004 documents for `1213`);
* what happens to the caller's *next* statements is version-dependent: on 10.11.18 and 11.8.9 `@@in_transaction` is `0`, so a statement issued after the error runs in autocommit and **survives the caller's `PDO::rollBack()`**; on 11.4.7 `@@in_transaction` is `1` (a fresh transaction is open), so the post-error write is rolled back by `rollBack()` and a later `commit()` would then commit only post-error work (inferred from `@@in_transaction`, not separately tested). In both shapes the caller's atomicity is silently broken if it swallows the exception.

`p4_propagation.php` ([`results/p4-propagation.txt`](results/p4-propagation.txt)) shows what the shipped runners do: the original `PDOException` `1020` reaches the caller unchanged, directly and through `PdoSavepointTransactionRunner` (its cleanup `ROLLBACK TO SAVEPOINT` cannot succeed and is swallowed, the original exception is rethrown); a runner-**owned** transaction is closed and rethrows `1020`; and `PdoDeadlockRetryTransactionRunner(…, 3)` does **not** replay it (1 attempt). Package behaviour in these traces is exception propagation without replay; that is **not** host transaction safety. The residual risk is a *caller* that catches the exception and keeps going — the same class of risk as `1213`, but now reachable by an ordinary stale-snapshot allocation.

## 6. Options evaluated

Constraints: v1.4.0 public contract, caller transaction ownership, savepoint semantics, no automatic change of consumer database settings, no weakening of correctness, MySQL parity.

| # | Option | Evidence | Verdict |
|---|---|---|---|
| A | Consumer sets `innodb_snapshot_isolation=OFF` (session/global/pool init) | `p5` R1: both APIs equal MySQL results. R2: also works mid-transaction | Works; consumer-owned. Disables the engine's lost-update protection for **all** of that connection's statements — a wide, host-wide trade |
| B | Consumer runs the allocating transaction under `READ COMMITTED` (`SET TRANSACTION ISOLATION LEVEL READ COMMITTED` before `BEGIN`) | `p5` R3/R4: results correct on MariaDB 11.8.9 | Works; consumer-owned; changes every read in that transaction to non-repeatable (and RC on MariaDB needs a `ROW`/`MIXED` binlog format, not tested here) |
| C | Consumer takes the serialization lock before the transaction's first plain read | `p5` R5; already the documented rule in `getNextPosition()` | Prevents the stale snapshot at its source (writers serialize instead of conflicting); needs a consumer-owned lock row, so it cannot be done by the package |
| D | Consumer restarts the whole transaction on `1020` | `p5` R6: first attempt `1020`, restart returns 6 | Correct and engine-endorsed ("try restarting transaction"); mandatory in any case, since the transaction is already gone (§5) |
| E | Package issues `SET SESSION innodb_snapshot_isolation=OFF` | works mechanically (`p5` R2) | **Rejected.** Mutates a connection the caller owns, leaks into the caller's later statements, variable does not exist on MySQL; violates "no automatic change of consumer settings" |
| F | Package switches isolation to `READ COMMITTED` | MariaDB/MySQL forbid changing characteristics inside a started transaction | **Rejected**, not possible mid-transaction |
| G | Package catches `1020` and falls back (plain read, retry the locking read) | after `1020` no transaction exists (§5); a "retry" executes in autocommit | **Rejected**, unsafe and impossible inside the caller's transaction |
| H | Package replays the work on `1020` | caller-owned transactions are never replayed (ADR 0003/0004); owned ones would need a new retry classification | **Rejected for caller-owned** (cannot restart the caller). For runner-owned: an optional future extension of the ADR 0004 generation, not needed to fix this issue and conflates two different errors if done carelessly |
| I | Package reads the latest state on a second connection | violates single-connection transaction ownership | **Rejected** |
| J | Package wraps the two locking statements in MariaDB `SET STATEMENT innodb_snapshot_isolation=OFF FOR …` (engine-aware, statement-scoped) | `p7`: removes `1020` for the locking read and the range `UPDATE`, accepted as a prepared statement, no session leak; `1064` on MySQL, so needs engine detection | **Technically viable, not recommended now.** It silently overrides a safety setting the host (or MariaDB's default) chose, for a fraction of the transaction's statements, with MariaDB-only SQL; the caller's own later `UPDATE`s on the same rows still raise `1020`, so the transaction's overall behaviour stays engine-dependent. It would also be a contract change (new engine-aware behaviour or configuration) |
| K | Throw a new typed exception for `1020` | changes the documented "propagates unchanged" contract; an additive classifier helper would be non-breaking but is not needed for correctness | Deferred; at most an additive, separately approved helper |

## 7. Recommendation (one)

**Keep the Runtime and Public API unchanged; declare an explicit, engine-conditional contract in which the call propagates the engine's error, and keep the remedy with the consumer.**

1. **Contract wording (documentation only, to be applied by the Lead's documentation owner to avoid collisions):** the Issue #33 guarantee of `getNextPositionForUpdate()` and the `moveWithinScope()` clamp inside a caller-owned transaction with an older `REPEATABLE READ` snapshot holds where the engine performs current reads for locking statements: MySQL 8.0/8.4 (tested) and MariaDB with `innodb_snapshot_isolation=OFF`. Where the engine forbids it (MariaDB with the setting `ON`, the 11.8 default), the call propagates the engine's unchanged `PDOException` `1020`. In every `1020` trace recorded in this study (P1–P5, P7, the PHPUnit runs listed in §3) the failing call returned no position and made no clamp decision; that is an observation of the tested traces on the tested engines, not a universal guarantee across engines, versions or concurrency schedules. "Fail closed" here means only exception propagation: it does not protect the host's transaction, because the engine has already ended it (§5) and a caller that swallows the exception can continue outside atomicity.
2. **Consumer obligations, in this order of preference:** (C) take the serialization lock before the first plain read; (B) allocate/move in a `READ COMMITTED` transaction; (A) set `innodb_snapshot_isolation=OFF` for the connection if the consumer accepts that trade; and in every case (D) treat `1020` as "the whole transaction is already rolled back — restart from `BEGIN`; never catch and continue". A package-owned outermost `moveWithinScope()` is unaffected.
3. **Why this and not J:** it is the only option that preserves data correctness, transaction ownership and the exact v1.4.0 contract without the package making a host-wide isolation decision on the consumer's behalf. It is honest about what is portable. J remains available as a future *opt-in* if the Owner decides MariaDB-default users must get MySQL semantics without any configuration (see Q1).

## 8. Is the fix possible inside the current contract?

* **Restoring the guarantee on MariaDB-default: no.** It needs the consumer's configuration (A/B/C) or a package decision to override the host's snapshot setting (E/J). E is incompatible with the stated constraints; J is a contract/architecture change.
* **Making the behaviour correct and diagnosable: yes, with documentation only.** The runtime already propagates `1020` unchanged and never replays it.

## 9. Proposed tests and negative controls (for after approval; none added now)

Engine-aware characterization tests run on the real engine, using the existing `OrderingFixture` pattern, only where the engine exposes the variable (skip otherwise, like the existing version-pinned tests):

1. `snapshot_isolation=ON`: stale-snapshot `getNextPositionForUpdate()` / `moveWithinScope()` → `PDOException` code `1020` and **the caller's earlier work in that transaction is lost** (a marker row written before the failing call is absent from a second connection, and its locks are released). That is the version-independent invariant. Do **not** assert `@@in_transaction`: it is `0` on 10.11.18 and 11.8.9 but `1` on 11.4.7 (a new implicit transaction), see §5.
2. Same scenario with the session set `OFF` and with `READ COMMITTED` → MySQL-identical results (this is the existing stale-snapshot test body).
3. Negative controls (must **not** raise `1020` with the setting `ON`): no concurrent change; concurrent change in another scope; package-owned outermost `moveWithinScope()`; serialization lock taken before the first plain read.
4. Propagation: `1020` through `PdoSavepointTransactionRunner` is the original exception; `PdoDeadlockRetryTransactionRunner(…, 3)` runs the callback once.
5. A MariaDB CI cell is useful but is gated by Issue #51 (the savepoint tests pin MySQL 8.4 and the deadlock-state tests assume MySQL post-deadlock behaviour); it must not be added from here.

Backward compatibility: no Runtime, signature, exception type or documented behaviour changes; only documentation wording becomes more precise. Error propagation: unchanged (`1020` is never treated as `1213`, never retried for caller-owned transactions).

## 10. Tested vs. untested cells

Tested: MySQL 8.0.46, 8.4.10; MariaDB 10.11.18, 11.4.7, 11.8.9; `REPEATABLE READ`; `innodb_snapshot_isolation` `0`/`1` where it exists; PHP 8.4.4 / mysqlnd; single connection pair on one host.
**Untested:** MariaDB 10.6.x, 11.0–11.3, 11.5–11.7, 12.x (the version in which the variable appeared and the version in which its default flipped are *not* verified here — only the observed 10.11.18/11.4.7/11.8.9 behaviour is claimed); `SERIALIZABLE`; MySQL 5.7/9.x; Percona/Galera/ProxySQL/PlanetScale-style layers; other PDO drivers/emulated prepares; `READ COMMITTED` with a `STATEMENT` binlog format; high-concurrency behaviour of option J.

## 11. Unresolved questions for the Owner

1. **Q1.** Is "fail closed + consumer remedy" (this recommendation) acceptable, or must MariaDB-default consumers get MySQL semantics with zero configuration, which would mean approving option J as an engine-aware, ideally opt-in, contract extension?
2. **Q2.** Should the documentation (README, Package Reference, the two How-To guides, the method DocBlocks) state the engine-conditional guarantee? Overlapping documentation edits must go through the Lead; none were made here.
3. **Q3.** Should an additive, non-breaking helper to classify `1020` (and to say "transaction already ended") be offered later, or is documentation enough?
4. **Q4.** Sequencing of Issue #51 (MariaDB verification target) and the release gate Issue #37 before any MariaDB CI cell; Issue #48 is independent.

## 12. Reproduce

See [`README.md`](README.md). Every number above is in `results/`; the probes are small and self-describing.
