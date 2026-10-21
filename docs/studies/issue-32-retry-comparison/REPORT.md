# Issue #32 — Immediate vs Hybrid retry: evidence report

> **Distribution note:** the raw-data directory `results/` referenced below is excluded from Composer/GitHub package archives (`.gitattributes` `export-ignore`, Issue #60) to keep the installed package small. It is unchanged in the Git repository: [browse `results/`](https://github.com/Maatify/persistence/tree/e4aba2043fe0956d7097621d22dfb23e4efbbcfa/docs/studies/issue-32-retry-comparison/results). Relative `results/...` links and commands in this document resolve only in a Git checkout.

> **Follow-up (Issue #42):** the `EVIDENCE REQUIRED` status of C2/C3 below was addressed by two pre-planned clean runs
> on an independent, qualified host (run-6, run-7): C2/C3 accepted under controlled conditions for the decision cell —
> see [`ISSUE-42-REPORT.md`](ISSUE-42-REPORT.md). The text below is the PR #41 report as merged; runs 1–5 are unchanged.

Study only. No change to `src/`, the public API, contracts or production-behaviour documentation; no Retry feature is implemented. Design and decision rule: [`METHODOLOGY.md`](METHODOLOGY.md) (rule committed before the main runs, `18cf99e`; post-review amendments in its §8 do not change the rule). Re-run: [`README.md`](README.md).

**Evidence sets.** The study was run **five times** on MySQL 8.0.46 (three full/partial runs, then two targeted repeats of the 8-connection decision cell):

| Run | Harness | Role |
|---|---|---|
| `results/run-1-initial` | first version (no per-attempt SQLSTATE, no harness hash; manifest reconstructed afterwards) | earlier replication; analysed with `--allow-legacy=1`; unchanged by the stricter analyzer |
| `results/run-2` | final (post-review) | replication; **a later part overlapped heavy load from another project on the same machine** — latency/throughput are unreliable, exhaustion counts are not affected in direction (§6) |
| **`results/run-3`** | final (post-review) | **primary evidence for the full design**; its quiet-host gate timed out (the host was never quiet) so it started under residual load, although none of its 20 decision-cell runs is flagged by the (load-average) criterion |
| `results/run-4-decision-cell` | final + fail-closed gate and CPU-based noise flags (review F41-05) | targeted repeat of the 8-connection decision cell (plus a probed replicate). Gate passed and 0/40 runs flagged — **but with the superseded name-based attribution of foreign CPU (`host-quiet` v1), which the third review (F41-08) showed can hide other projects' load. Its host cleanliness is therefore not certified**; kept unchanged, analysed with `--allow-legacy=1` |
| **`results/run-5-decision-cell`** | final + process-tree attribution (`host-quiet` v2, F41-07/08) | corrected targeted repeat of the decision cell (plus probed replicate). Gate passed at start, **but the host became busy during the runs: 34 of 40 runs are flagged noisy (14 of 20 in the decision cell, 20 of 20 probed)**, so the analyzer marks both cells `EVIDENCE REQUIRED` for latency/throughput |

Numbers below are run-3 unless stated; run-4 and run-5 are §1a. All analyses validated their input fail-closed (run-3: 220 declared files; run-4 and run-5: 40 each).

## 1. Recommendation

**`HYBRID` — at the pre-specified 8-worker synchronized stress cell.** This label is deliberate: the study does not show that Hybrid is better for every consumer of the library (§6, §7).

Decision cell (8 connections, sequential placement, barrier waves, 10 rounds × 400 operations per candidate, `REPEATABLE READ`):

| | Immediate | Hybrid |
|---|---:|---:|
| Exhausted operations (4 attempts, all deadlock) | **542 / 4000 (13.55 %)** | **5 / 4000 (0.12 %)** |
| Hybrid wins on exhaustion (≥ 20 % rel. and ≥ 1 pp) | — | **10 of 10 rounds** (need 8) |
| 95 % CI of Δ exhausted (Hybrid − Immediate) | — | **[−14.52, −12.39] pp** |
| Goodput (successful ops / active s) | 192.6 | 185.1 (ratio **0.961**, limit ≥ 0.950) |
| P95 latency, all ops | 40.30 ms | 47.79 ms (Δ **+7.49 ms**, allowance 10 ms) |
| Transaction guarantees | pass | pass |

All four criteria pass in run-1, run-2 (latency/throughput unreliable, §6) and run-3 (exhausted Immediate → Hybrid: 616 → 10, 503 → 12, 542 → 5; 10/10 rounds each time); run-4 and run-5 add 537 → 15 and 428 → 43, also 10/10, but their latency/throughput acceptance is `EVIDENCE REQUIRED` (§1a). **The margins on C2 and C3 are thin in run-3** (goodput −3.9 % against a 5 % limit; P95 +7.5 ms against 10 ms), and one replicate cell fails C3 by 0.3 ms (§3). The exhaustion effect is large and stable; the cost side is small but close to the pre-registered tolerances.

The same direction holds at 16 connections (32.75 % → 1.43 %, goodput ×1.226).

### 1a. Targeted repeats of the decision cell (run-4 and run-5, reviews F41-05 / F41-07 / F41-08)

**Performance acceptance is `EVIDENCE REQUIRED`; the exhaustion result is not in doubt.**

Exhaustion, 8 connections, synchronized waves, 10 rounds × 400 operations per candidate — five independent measurements, all 10/10 rounds won by Hybrid:

| Run | Immediate exhausted | Hybrid exhausted | CI of Δ (Hybrid − Immediate) |
|---|---:|---:|---|
| run-1 | 616 | 10 | — |
| run-2 | 503 | 12 | [−13.35, −11.26] pp |
| run-3 | 542 | 5 | [−14.52, −12.39] pp |
| run-4 | 537 | 15 | [−14.15, −12.00] pp |
| run-5 | 428 | 43 | [−10.66, −8.63] pp |

Latency/throughput acceptance (C2/C3), by run:

| Run | goodput ratio | ΔP95 | Host evidence | Rule outcome |
|---|---:|---:|---|---|
| run-1 | 1.029 | +2.1 ms | load not recorded | HYBRID |
| run-3 | 0.961 | +7.5 ms | load-average flag only; gate timed out | HYBRID |
| run-4 | 1.022 | +2.2 ms | gate passed, 0/20 flagged **with name-based attribution (superseded)** | HYBRID, but host cleanliness not certified |
| run-5 | 1.104 | −10.4 ms (both ≈ 330–340 ms) | process-tree attribution; **14/20 runs noisy**; goodput ≈ 62–68 ops/s vs ≈ 145–190 earlier | INCONCLUSIVE — rule outcome would have been HYBRID |

What this means: the earlier "controlled" claim for run-4 cannot be kept. After the attribution fix the only run with trustworthy host evidence (run-5) shows the shared laptop was heavily loaded during the repeat (latencies roughly five times higher than in quiet runs), so its latency/throughput numbers cannot support or refute C2/C3. The decision-cell cost side (goodput ratio 0.96–1.10, ΔP95 between −10 and +8 ms across runs, +10.9 ms probed) is therefore **not established under controlled host load**. A clean confirmation needs an isolated host or a quiet window (command in README: `run-all.sh <dir> decision`; the gate is fail-closed and mid-run noise is flagged and downgrades the cell).

**The P95 counter-signal remains.** Probed replicates (per-retry server-side probe on both candidates) give ΔP95 +10.31 ms (run-3 `integrity-verify`) and +10.92 ms (run-4), both just above the 10 ms tolerance, with the same exhaustion result; run-5's probed cell is noisy (20/20). Unprobed cells give +2.1, +7.5 and +2.2 ms (+ the unreliable run-5 value). The probe adds one `performance_schema` query before every retry on both candidates and the production runner has no such probe, so the unprobed cells are the representative ones — but the data do not show *why* the probe penalises Hybrid's P95 more, so the summary is: Hybrid's P95 cost at 8 connections lies between about +2 ms and +11 ms depending on measurement conditions, straddling the tolerance.

## 2. Reproduction of the Issue #32 scenario

Scenario: php-product `ContentLifecycle::create()` (first Content of a new Product), the same seven statements and `FORCE INDEX` hints, against the verbatim php-product schema (`schema/php-product-schema.sql`; `EXPLAIN` of every statement is in `results/run-3/diagnosis.json` and uses the intended keys: `PRIMARY`, `uq_maa_product_options_product_code`, `idx_maa_product_variants_product_status_deleted_order`, `uq_maa_product_product_contents_identity`).

Cause, observed (not assumed):

- After the locking reads, each of two transactions on **different** new Products holds a lock on the **supremum** of the Option, Variant and Content indexes (`data_locks`: `X` on `uq_maa_product_options_product_code`, `X` on the variants index, `S` on `uq_maa_product_product_contents_identity`, all `supremum pseudo-record`). These do not conflict; both reads returned without blocking.
- Each transaction then `INSERT`s into the Content index, which needs an insert-intention lock on exactly that gap, held by the other. A single insert with the other transaction open waited until `innodb_lock_wait_timeout` (1 s in the probe) — the insert is blocked by the other transaction's gap lock.
- InnoDB's own report (`LATEST DETECTED DEADLOCK`, in `diagnosis.json`) shows the two inserts, each holding `lock mode S` on the supremum of `uq_maa_product_product_contents_identity` and waiting for `lock_mode X insert intention`; one is rolled back with `1213`/`40001`.

No-retry baseline (policy `none`, one attempt, 5 rounds × 400 ops): **49.95 %** of operations fail with `1213` at 2 connections (every two-product wave loses exactly one transaction; the Issue measured 9 of 20 = 45 %), **87.5 %** at 8 connections. Zero non-deadlock errors and zero `invalid` operations in the whole study.

## 3. Results across the design (run-3)

Pooled over 10 rounds (4000 ops per candidate per cell; `integrity-verify` 5 rounds × 400). "exh." = exhausted operations. Decision cell in bold. Rule outcome = the pre-registered rule applied to that cell; only the decision cell carries the verdict.

| Cell (placement / mode, connections) | Immediate exh. | Hybrid exh. | rounds Hybrid wins | goodput Imm → Hyb (ops/s) | P95 Imm → Hyb (ms) | Rule outcome |
|---|---:|---:|---:|---:|---:|---|
| sequential / wave, 2 † | 0 | 0 | 0 | 187 → 177 | 12.3 → 13.0 | INCONCLUSIVE (nothing to improve) |
| sequential / wave, 4 | 0 | 0 | 0 | 203 → 148 | 23.0 → 31.2 | INCONCLUSIVE (nothing to improve; Hybrid −27 % goodput) |
| **sequential / wave, 8** | **542** | **5** | **10/10** | **193 → 185** | **40.3 → 47.8** | **HYBRID** |
| sequential / wave, 16 | 1310 | 57 | 10/10 | 188 → 230 | 64.3 → 70.2 | HYBRID |
| integrity-verify, 8 (5 rounds, extra probes) | 314 | 4 | 5/5 | 183 → 175 | 40.1 → 50.4 | IMMEDIATE (C3 fails by 0.3 ms: Δ +10.31 ms vs 10 ms) |
| shuffled / wave, 2 † | 0 | 0 | 0 | 333 → 334 | 8.5 → 8.3 | INCONCLUSIVE |
| shuffled / wave, 8 | 8 | 0 | 0 | 602 → 586 | 16.9 → 16.9 | INCONCLUSIVE (8 < 30 exhausted) |
| shuffled / wave, 16 | 61 | 1 | 8/10 | 730 → 731 | 32.1 → 28.0 | HYBRID |
| sequential / free-run, 4 † | 1 | 0 | 0 | 496 → 494 | 12.0 → 11.5 | INCONCLUSIVE |
| sequential / free-run, 8 | 24 | 1 | 2/10 | 670 → 680 | 16.6 → 15.7 | INCONCLUSIVE (24 < 30) |
| sequential / free-run, 16 | 42 | 1 | 5/10 | 797 → 848 | 39.9 → 34.0 | INCONCLUSIVE (not consistent: 5 < 8) |

† cell contains runs flagged as noisy by `load-report.php` (see `results/run-3/LOAD.md`: 31 of 220 runs, all in cells with 0–1 Immediate exhaustions or the no-retry baseline; **none in the decision cell**).

The `integrity-verify` row is an honest counter-signal and is kept: those runs add a server-side probe before every retry (on both candidates), and with only 5 rounds Hybrid's P95 exceeded the allowance by 0.3 ms. Together with +2.1 ms (run-1) and +7.5 ms (run-3) in the decision cell, the P95 cost of Hybrid at 8 connections is **between +2 and +10 ms — at the edge of the 10 ms tolerance**, not comfortably inside it.

Replication of the decision cell across runs:

| Run | Immediate exh. | Hybrid exh. | rounds won | goodput ratio | ΔP95 | Outcome |
|---|---:|---:|---:|---:|---:|---|
| run-1 (earlier harness) | 616 | 10 | 10/10 | 1.029 | +2.1 ms | HYBRID |
| run-2 (final harness, **load-contaminated**) | 503 | 12 | 10/10 | 1.468 | −154 ms (unreliable) | HYBRID |
| **run-3 (final harness, primary)** | **542** | **5** | **10/10** | **0.961** | **+7.5 ms** | **HYBRID** |
| run-4 (gate passed; superseded name-based host attribution) | 537 | 15 | 10/10 | 1.022 | +2.2 ms | HYBRID (host cleanliness not certified) |
| run-4 probed replicate (same caveat) | 538 | 16 | 10/10 | 0.980 | +10.9 ms | IMMEDIATE (C3) |
| run-5 (process-tree attribution; 14/20 noisy) | 428 | 43 | 10/10 | 1.104 | −10.4 ms | INCONCLUSIVE (EVIDENCE REQUIRED; would have been HYBRID) |
| run-5 probed replicate (20/20 noisy) | 551 | 16 | 10/10 | 1.551 | −182 ms | INCONCLUSIVE (EVIDENCE REQUIRED) |

Per-round tables, deadlock counts (original vs repeated), attempts-per-op, P50/P95/P99 and the attempts histogram for every cell are in `results/<run>/ANALYSIS.md`.

## 4. Interpretation of the differences

- **The original deadlock is identical for both policies** (8 conns: 3500 vs 3499 first-attempt deadlocks): a retry policy cannot change it, only what happens next.
- **Why Immediate exhausts.** After a victim is rolled back, the other transactions of the wave still hold the supremum locks and are about to insert. An immediate retry re-takes the same locks within microseconds and enters the same cycle again. At 8 connections 3 of 4 deadlocked operations need more than two attempts and 13.6 % never get through in four.
- **Why Hybrid helps.** Retry 1 (immediate) usually fails again for the same reason; the jittered waits before retries 2 and 3 let competitors commit and de-synchronise the survivors. Operations that succeed on attempt 3 rise from 1144 to 2247 (8 conns); repeated deadlocks fall 37 % (4560 → 2880).
- **What it costs.** 15.9 s of jitter sleep over the 10 rounds at 8 connections; completed-ops/s falls 17 % (Immediate counts fast failures as completed work), P95 rises 7.5 ms, successful throughput falls 3.9 % — the extra successes almost, not quite, pay for the waiting. Where there is no exhaustion to save (4 connections) the waiting is pure cost: −27 % goodput, +8 ms P95.
- **2 connections:** every wave has exactly one victim and its first immediate retry always succeeds, so both policies behave identically (the jitter never triggers).

## 5. Guarantees (C4) and harness controls

- Every attempt was one call to the unmodified runner with `PDO::inTransaction()` false before it and false after each failure; retries only after `1213` + SQLSTATE `40001`; ≤ 4 attempts; zero `error` and zero `invalid` operations (run-2 and run-3; run-1 predates the `invalid` category but passes the same checks).
- Verify runs: `performance_schema.events_transactions_current` showed no `ACTIVE` transaction on the connection before any of the thousands of retries probed; after every run no `ACTIVE`/`INNODB_TRX` transaction remained.
- Callback executed exactly once per attempt; no attempt started before the previous one ended plus its sleep; Hybrid sleeps within 0 / 10 / 20 ms; stored rows ⇔ successful operations exactly (no duplicates, no missing, none for exhausted operations); isolation `REPEATABLE-READ` on every connection; client deadlocks = server `lock_deadlocks` delta in every run.
- **Observer sensitivity (review F41-03):** `preflight/*.json` (all checks pass) records that instrumentation is enabled and that a deliberately open transaction *is* seen by the per-retry probe, the end-of-run probe and `INNODB_TRX`, and not after rollback. `selftest.txt` (30/30 pass) shows the preflight fails when `events_transactions_current` is disabled — so an empty probe result is not vacuous.
- **Branch tests (F41-02):** `selftest.txt` covers `1213`+`40001` retried; `1213` with another SQLSTATE, `1205`, `1062` and non-PDO throwables not retried; transaction still active after a failure or before an attempt, and server-reported active transaction before a retry → `invalid` with no further attempt; exhaustion after exactly four attempts; Hybrid sleep bounds over 200 seeds. These use fakes only for the decision branches; the deadlock evidence is from real MySQL.
- **Reproducibility from an empty database (F41-04):** `results/f41-04-fresh-database-proof.txt` records the RED failure (error 1146 at `SHOW CREATE TABLE` on an empty database, before the fix) and the GREEN runs of both the documented single-cell command and `run-all.sh smoke` from a dropped-and-recreated empty database on a fresh MySQL 8.0.46 container. The recorded results were not changed.
- **Repository gates (F41-06):** `results/f41-06-local-gates.txt` — the full CONTRIBUTING.md sequence on the final tree (`composer install`, `validate --strict`, strict-PSR autoload, platform check, `audit`, `analyse`, unit 260, regression 51, integration 153 on MySQL 8.4.11 with `log_bin_trust_function_creators=1`, consumer, php-cs-fixer dry-run, `git diff --check`, actionlint) all exit 0; the two earlier attempts with wrong prerequisites are recorded there.
- **Host-evidence integrity (F41-07/F41-08):** `analyze.php` re-computes the host classification from the raw `host-load.log` and the run summaries and rejects a `load.json` that is missing, malformed, incomplete, has undeclared/missing runs, or whose per-run samples/noisy flags/noisy list differ from the recomputation (10 negative self-tests). `host-quiet.sh` (v3, fail-closed: a failed `top`/`docker stats` yields `INVALID`, never 0 %; Docker-stats failure counts the whole VM as foreign) attributes own processes by process **tree** (descendants of the run root), never by name, and counts other containers' CPU as foreign; self-tests show deliberately busy processes named `worker.php`/`study.php`/`bash` are foreign unless they descend from the study root. `selftest.txt` of run-5: 44/44 (run-5 predates the v3 sensor fail-closed change; its host log therefore contains only valid samples, which the analyzer verified). The F41-10 sensor-failure tests (forced `top`/`docker stats` failure, empty and non-numeric output, flaky gate) are in the current `harness/selftest.php`: 50/50 without a database.
- **Evidence integrity (F41-01):** the analyzer refuses missing/extra/duplicated/inconsistent run files, mixed harness or MySQL versions, and reused output directories (negative tests in `selftest.txt`).

## 6. Limits of the measurement

1. **Contention level decides the winner.** The verdict rests on the declared decision cell (8 simultaneous creators in a synchronised wave, 87.5 % no-retry failure) — a worst-case arrival pattern. With barrier-free streaming or shuffled placement at 8 connections Immediate exhausted only 24 and 8 of 4000 operations (below the pre-registered minimum of 30, so INCONCLUSIVE); Hybrid did at least as well but no significant benefit can be claimed. The benefit becomes clear again at 16 connections.
2. **Hybrid is not free:** −27 % goodput at 4 synchronised connections (no exhaustion avoided), and at 8 connections the P95 cost is close to the tolerance (§3).
3. **Host isolation was not achieved.** The measurement host is a shared developer laptop (Docker-Desktop VM, 8 vCPUs). Run-4's "0 of 40 noisy" used a foreign-CPU attribution that excluded processes by *name* (`worker.php`, `bash`, …) and the whole Docker VM, which can hide other projects' load; the process-tree attribution (v2) that replaced it was applied in run-5, where the gate passed at start (foreign CPU 147 %) but 34 of 40 runs overlapped heavy foreign load. Performance acceptance (C2/C3) is therefore `EVIDENCE REQUIRED`, not "clean". Only an isolated host or a verified quiet window can settle it.
4. **Run-2 was contaminated** by another project's test suite running on the same machine (host load average ≈ 45–55 on 8 vCPUs, observed afterwards; rounds 8–10 of the 8-connection cell are ~3× slower for *both* candidates). Run-2's exhaustion counts and the 10/10 round pattern are consistent with run-1/run-3, but its throughput/latency criteria (e.g. its 16-connection P95 failure, its ratio 1.468) are not interpretable. Run-2 is kept, flagged, not edited. Load was not recorded for run-1/run-2. Run-3's quiet-host gate **timed out after 30 min** (`quiet_samples=0`) and the run proceeded; its load-average flag (allowance connections + 2 + 6 = 16 at 8 connections) was too permissive to prove isolation (decision-cell load average 7.5–13.5), which is why the gate was made fail-closed and noise is measured as foreign CPU; see items 3 and §1a for run-4/run-5. Run-1…run-5 are unchanged and kept.
5. **One lock pattern** (the Issue's), not the whole php-product workload.
6. **Environment:** one Docker-Desktop VM on macOS (linuxkit), 8 vCPUs shared by MySQL and up to 16 PHP processes, default durability on virtualised storage. Absolute figures do not transfer; the paired comparison does. MariaDB and other MySQL versions were not measured.
7. **Metrics:** waves exclude a constant 3 ms release lead from the throughput denominator; goodput was fixed as the throughput criterion after a smoke run (METHODOLOGY §6); "4 of 5 rounds" scaled to 8 of 10; P99 of 400 samples is coarse (pooled P99 over 4000 reported).
8. **Statistics:** operations within a wave are not independent, so the Newcombe interval understates uncertainty; the 10/10 round consistency across five runs is the stronger evidence. No multiple-comparison correction on the sensitivity cells (they carry no verdict).
9. **Out of scope by design:** `1205`, caller-owned transactions and savepoints (no retry there), more than three retries, and any API design.

## 7. For the architectural decision (owner)

- Supported by the data: in a burst where ≥ 8 runner-owned transactions contend for the same gap, an immediate-only policy leaves 14–33 % of operations failing after four attempts, while Hybrid leaves 0.1–1.4 %, at a small cost in P95 and goodput that sits close to the pre-registered tolerances.
- Not supported by the data: a universal default. For the Issue's own two-Product case one immediate retry suffices (both policies identical); at moderate synchronised contention (4 connections) Hybrid costs ~27 % goodput with nothing to rescue; under less synchronised loads at 8 connections the evidence is insufficient either way.
- The exhaustion reduction is established (five runs, 10/10 rounds each); latency/throughput acceptance under controlled host load is not (§1a, §6.3) and stays `EVIDENCE REQUIRED`.
- The owner must decide how to trade moderate-load cost against burst protection (for example whether jitter should be opt-in, or configurable) before approving production semantics. This study makes no such decision and authorises no implementation.
