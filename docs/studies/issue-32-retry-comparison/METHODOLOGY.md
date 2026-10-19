# Issue #32 — Immediate vs Hybrid retry: methodology (pre-registered)

Status: written and committed **before** the main measurement runs. Scope: study only; no change to `src/`, the public API, contracts, or production behaviour documentation. Nothing here implements a Retry feature.

## 1. Question

For transactions **owned by the runner**, when MySQL kills one with deadlock `1213`, which re-run policy (maximum 3 retries = 4 attempts in total) is better:

| Policy | Retry 1 | Retry 2 | Retry 3 |
|---|---|---|---|
| **Immediate** | 0 ms | 0 ms | 0 ms |
| **Hybrid** | 0 ms | uniform 0–10 ms | uniform 0–20 ms |
| `none` (reference only, not a candidate) | — (1 attempt) | | |

Caller-owned transactions and savepoints are out of scope: the harness only invokes a policy when `PDO::inTransaction()` is false before the attempt (asserted for every attempt), so the runner owns the transaction.

## 2. Implementation of the policy (outside the library)

`harness/lib.php::runOperation()` wraps the **unmodified** `PdoSavepointTransactionRunner::run()`. Each attempt is one `run()` call, i.e. a fresh `BEGIN … COMMIT/ROLLBACK` owned by the runner. The harness catches `PDOException`, retries only when `errorInfo[1] === 1213` (SQLSTATE `40001`) and attempts remain, sleeps per policy with `usleep()` (Hybrid jitter from a seeded `mt_rand`), and lets any other error end the operation (counted as `error`). After exhaustion the operation is recorded as `exhausted`; the original exception is the last one caught. `PdoSavepointTransactionRunner` is the runner php-product uses for all mutations (its `run()` delegates to the same `PdoTransactionRunner` when no transaction is active).

## 3. Scenario (reproduction of Issue #32)

Source: `maatify/php-product` PR #7 @ `77d6aafbb48ff37e2ae07a24230b838848799ebd`, `ContentLifecycle::create()` for a Product-owned Content ("create the first Content (name) of a new Product"). The harness issues the same SQL, in the same order, with the same `FORCE INDEX` hints, against the verbatim php-product schema (`schema/php-product-schema.sql`, blob `753a5900…`, all 10 tables, all indexes):

1. `SELECT … FROM maa_product_products WHERE id = ? LIMIT 1` (plain read)
2. `SELECT … FROM maa_product_products WHERE id = ? FOR UPDATE`
3. `SELECT … FROM maa_product_options FORCE INDEX (uq_maa_product_options_product_code) WHERE product_id = ? ORDER BY id FOR UPDATE`
4. option-values join, `FOR UPDATE`
5. `SELECT … FROM maa_product_variants FORCE INDEX (idx_maa_product_variants_product_status_deleted_order) WHERE product_id = ? ORDER BY id FOR UPDATE`
6. `SELECT 1 FROM maa_product_product_contents FORCE INDEX (uq_maa_product_product_contents_identity) WHERE product_id = ? AND deleted_at IS NULL AND name REGEXP '[^[:space:]]' LIMIT 1 FOR SHARE`
7. `INSERT INTO maa_product_product_contents …`

(php-product skips its composition/label follow-up reads when the Option/Value/Variant sets are empty, which they are for a new Product.) `diagnose` records `EXPLAIN` for every statement, `performance_schema.data_locks` after the locking reads of two transactions on two different new Products, the blocked insert, and InnoDB's own `LATEST DETECTED DEADLOCK` report.

## 4. Environment and isolation

- MySQL 8.0.46 (the version in the Issue) in Docker, `127.0.0.1`, default InnoDB settings (deadlock detection ON, `innodb_lock_wait_timeout` 50, `innodb_flush_log_at_trx_commit` 1). Every connection runs `SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ` and reports `@@transaction_isolation`, which is verified per run.
- Each worker is a separate OS process with its own PDO connection (PHP is single-threaded, so processes are the only way to obtain real concurrency). Deadlocks are real InnoDB deadlocks: every failure counted has driver code `1213`/SQLSTATE `40001` and the client count is reconciled with the server counter `INFORMATION_SCHEMA.INNODB_METRICS.lock_deadlocks` for every run.
- `environment.json` stores versions, relevant `SHOW VARIABLES`, the `SHOW CREATE TABLE` of the involved tables, the repo commit and the schema hash.

## 5. Design and measurement

- **Load**: every operation inserts the first Content of one new, pre-committed Product; one operation per Product. Same Product set, schema, indexes and connection count for both candidates.
- **Reset**: before every (candidate, round) run the database is dropped and recreated from the schema file, products are re-inserted (ids restart at 1), workers are fresh processes.
- **Waves** (primary mode): the parent releases all `C` workers on the same monotonic instant (`hrtime`, busy-wait barrier), each runs one operation including all its retries, the parent waits for all, then releases the next wave. This reproduces "N unrelated aggregates created concurrently" deterministically and identically for both candidates. Placement `sequential` = Products in id order (the Issue's pattern); `shuffled` = seeded permutation, same for both candidates (sensitivity).
- **Free-run** (sensitivity): each worker streams its own operations with no barrier.
- **Levels**: 2 connections (bounded contention, the Issue's case), 4, 8, 16. Every run has 400 operations per candidate (≥ 200 required). **Decision level = 8 connections, `sequential`, wave mode** (declared before measuring; dense contention, below the 16-connection extreme). The other cells are reported as sensitivity and are not used to pick the verdict.
- **Rounds**: 10 independent rounds per level (≥ 5 required). Order is balanced: odd rounds run Immediate first, even rounds Hybrid first (5 + 5). The Issue's "4 of 5 rounds" is scaled to the same 80 % → `ceil(0.8 × rounds)` = **8 of 10**.
- **Per operation** the harness stores: every attempt (start offset µs, duration µs, error code, sleep µs), outcome (`ok` / `exhausted` / `error`), the number of callback executions, total latency (first attempt start → last attempt end, including jitter sleeps). Raw files: `results/*/runs/*.jsonl.gz` (one JSON line per operation + one summary line).
- **Metrics** (`harness/analyze.php`): attempts; original deadlocks (on attempt 1) and repeated deadlocks (attempt ≥ 2); exhausted operations; success rate; **throughput = goodput = successful operations per *active* second** (wave: Σ(last completion − release instant), excluding the constant 3 ms barrier lead; free-run: release → last completion); completed ops/s is also reported but is informational because it counts a fast failure as work done; latency P50/P95/P99 by nearest-rank (`ceil(p·n)`), over **all** operations (the caller also waits for failures) and, secondarily, succeeded-only; P99 only for n ≥ 100. Pooled values are computed over all rounds; Wilson 95 % intervals for rates; Newcombe hybrid-score 95 % interval for the difference of exhausted proportions.
- **Guarantee checks** (every run): no operation ends with a transaction open on the client (`inTransaction()` after every failure and before every attempt); `events_transactions_current` shows no `ACTIVE` transaction of the connection before any retry (separate `verify=1` runs, so the check does not perturb timing) and no `ACTIVE` transaction at all and no `INNODB_TRX` row after a run; the callback executed exactly once per attempt; no attempt started before the previous one ended plus its sleep; Hybrid sleeps within `[0,0]`, `[0,10 ms]`, `[0,20 ms]`; ≤ 4 attempts; no retry after a non-1213 outcome; stored rows ⇔ successful operations exactly (no duplicate, no missing, no row for an exhausted operation, names match); client deadlocks = server `lock_deadlocks` delta; zero `error` outcomes.

## 6. Pre-registered decision rule (applied at the decision level)

Constants are in `harness/analyze.php`.

- **Sufficient sample**: ≥ 5 rounds, ≥ 200 operations per candidate per round, and ≥ 30 pooled Immediate exhausted operations. Otherwise `INCONCLUSIVE`.
- **C1 exhaustion**: pooled relative reduction ≥ 20 % **and** absolute ≥ 1 percentage point; achieved in ≥ 80 % of rounds (8 of 10) by the same two thresholds applied per round; and the Newcombe 95 % interval of (Hybrid − Immediate) excludes 0.
- **C2 throughput**: pooled goodput(Hybrid) ≥ 0.95 × goodput(Immediate).
- **C3 P95**: pooled P95(Hybrid) ≤ P95(Immediate) + max(10 % of P95(Immediate), 10 ms), over all operations.
- **C4 guarantees**: every guarantee check above passes in every run of both candidates.
- **Verdict**: `HYBRID` if sample sufficient and C1–C4 pass. `INCONCLUSIVE` if the sample is insufficient, if any C4 check fails, or if C1 passes pooled but not consistently across rounds (4–7 of 10 rounds). Otherwise (adequate sample, guarantees intact, Hybrid fails C1/C2/C3) `IMMEDIATE`.

A transparency note: the C2 definition was fixed after a 1-round, 80-operation smoke run (not part of the results, not committed) showed that counting completed operations (including failed ones) as throughput rewards fast failure; goodput was therefore chosen as the criterion and completed ops/s kept as an informational column. The same smoke run also suggested that Hybrid lowers exhaustion at ≥ 8 connections; that is the hypothesis under test, not a result. Everything else in this section was fixed before that smoke run's numbers were read.

## 7. Known limits (also repeated in the report)

Single host, Docker Desktop VM on macOS (fsync/commit latency is not representative of production storage); workers and MySQL share 8 vCPUs; one table-gap-lock pattern (the Issue's) rather than the full php-product workload; synthetic barrier waves are a worst-case arrival pattern; absolute latencies are not transferable, only the paired comparison is.

## 8. Amendments after independent reviews of PR #41 (decision rule unchanged)

The decision rule, thresholds and decision cell of §6 were **not** changed. The review (F41-01..03) required the following harness changes, made after run-1 and before run-2/run-3; they harden evidence handling and do not alter which operations are counted:

- **Retry classifier (F41-02).** One classifier, `isDeadlock()`: driver code `1213` **and** SQLSTATE `40001`. Everything else (1205, 1062, `1213` with another SQLSTATE, any non-PDO `Throwable`) ends the operation as `error` without retry. If the connection is inside a transaction before an attempt, or still inside one after a failure (ownership/cleanup uncertain), or the server reports an `ACTIVE` transaction before a retry (verify runs), the operation ends as `invalid` and **no further attempt runs**; any `invalid` operation fails the run's guarantee check. Every attempt record now carries its SQLSTATE. The retry loop is a database-free function (`executeWithPolicy`) so each branch has a deterministic negative test (`harness/selftest.php`); the tests use fakes only for these decision branches, never as deadlock evidence.
- **Observer sensitivity (F41-03).** Before every `run` invocation `preflight()` checks that `performance_schema` and the `global_instrumentation`, `thread_instrumentation`, `events_transactions_current` consumers and the `transaction` instrument are enabled and that the connection thread is instrumented, then runs a **positive control**: a deliberately open transaction must be seen by the per-retry probe, the end-of-run probe and (after the 100 ms cache) `INNODB_TRX`, and none of them may see it after rollback. A failing preflight aborts the run. `selftest.php --with-db=1` additionally disables `events_transactions_current` and asserts that the preflight then fails. Results: `preflight/<tag>.json` in each results directory.
- **Fail-closed evidence (F41-01).** Each `run` writes `manifest.json` (which cells/rounds/policies must exist). `analyze.php` refuses to produce any analysis unless: every declared run file is present and none undeclared; file name, summary and manifest agree; raw operation count = summary = manifest; raw outcome counts and raw `1213` counts equal the summary; operation/product ids are unique; all runs share one harness hash (`harness_sha256`) and one MySQL version; comparisons use paired rounds only. `study.php run` refuses an output directory that already holds runs for the tag (`--resume=1` continues only an identical configuration and validates each existing file, including the harness hash). `selftest.php` contains negative tests for missing pairs, mismatched counts, duplicates, undeclared files, mixed harness versions, missing manifest, legacy files and directory reuse.
- **Legacy run-1.** `results/run-1-initial` was produced by the earlier harness (no SQLSTATE per attempt, no harness hash, no manifest). Its manifest was reconstructed afterwards from `run-all.sh` and it is analysed with `--allow-legacy=1`; its decisions and numbers are unchanged by the stricter analyzer. It is kept as an independent earlier replication, not as the primary evidence.
- **Host load.** After run-2 showed a slowdown in later rounds that affected both candidates (another project's test suite was running on the same machine), `host-load.sh` samples the host load average every 2 s into `host-load.log`, `run-all.sh` waits for a quiet host before starting, and `load-report.php` flags runs that overlapped a busy host. Runs are flagged, never silently dropped or re-run.

Second review (Fresh Full Re-Review, F41-04..06), again without changing the rule:

- **F41-04.** `captureEnvironment()` now creates the schema before `SHOW CREATE TABLE`, so every entry point works from an empty database (proof: `results/f41-04-fresh-database-proof.txt`).
- **F41-05.** The quiet-host gate in `run-all.sh` is **fail-closed**: foreign CPU (CPU of processes outside the study, `host-quiet.sh`) below 200 % of one core (25 % of the 8 vCPUs) for 15 consecutive ~2 s samples within 40 minutes, else exit 3 and nothing starts (`ALLOW_NOISY=1` overrides and is recorded). `host-load.sh` records foreign CPU and load average during the runs; `load-report.php` flags a run as noisy when its mean foreign CPU exceeds 200 % or its peak 400 %, or when it has no samples; `analyze.php` requires `load.json` (unless `--allow-legacy=1`) and turns a cell containing noisy runs into INCONCLUSIVE (performance acceptance EVIDENCE REQUIRED). The run-3 load-average flag (connections + 2 + 6) is kept only for run-3's own historical report. The harness hash now covers only the files that determine what a run does and records (`diagnose.php`, `lib.php`, `study.php`, `worker.php`). `run-all.sh decision` repeats only the 8-connection decision cell (plus a probed replicate); results: `results/run-4-decision-cell`.
- **F41-06.** Full local gates recorded in `results/f41-06-local-gates.txt`.
