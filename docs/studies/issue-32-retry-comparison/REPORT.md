# Issue #32 — Immediate vs Hybrid retry: evidence report

Study only. No change to `src/`, the public API, contracts or production-behaviour documentation; no Retry feature is implemented. Design and decision rule: [`METHODOLOGY.md`](METHODOLOGY.md) (committed before the main runs, `18cf99e`). Re-run: [`README.md`](README.md). Raw data: `results/main/runs/*.jsonl.gz`; generated tables: `results/main/ANALYSIS.md`, `analysis.json`.

## 1. Recommendation

**`HYBRID`** — by the pre-registered rule, at the declared decision cell (8 connections, 10 rounds × 400 operations per candidate, `REPEATABLE READ`, real MySQL 8.0.46):

| | Immediate | Hybrid |
|---|---:|---:|
| Exhausted operations (4 attempts, all `1213`) | **616 / 4000 (15.40 %)** | **10 / 4000 (0.25 %)** |
| Hybrid wins on exhaustion (≥ 20 % rel. and ≥ 1 pp) | — | **10 of 10 rounds** (need 8) |
| 95 % CI of Δ exhausted (Hybrid − Immediate) | — | **[−16.31, −14.04] pp** |
| Goodput (successful ops / active s) | 176.3 | 181.5 (ratio **1.029**, limit ≥ 0.95) |
| P95 latency, all ops | 48.22 ms | 50.34 ms (Δ **+2.11 ms**, allowance 10 ms) |
| Transaction guarantees | pass | pass |

The same direction holds at 16 connections (33.58 % → 1.20 %, 10/10 rounds, goodput ×1.271) and in the separate guarantee runs at 8 connections (15.70 % → 0.15 %, 5/5 rounds).

**The recommendation is conditional, and the owner should read §6 before adopting it.** The advantage appears only where Immediate actually runs out of attempts. At 2 connections neither policy exhausts anything (0/4000 each) so the choice is irrelevant there, and at 4 connections neither exhausts but Hybrid's jitter costs **−25 % goodput** (195.1 → 146.7 ops/s) and +7 ms P95. In realistic, non-synchronised arrival patterns the number of Immediate exhaustions is small (§5), so the verdict there is not established by this study.

## 2. Reproduction of the Issue #32 scenario

Scenario: php-product `ContentLifecycle::create()` (first Content of a new Product), the same seven statements and `FORCE INDEX` hints, against the verbatim php-product schema (`schema/php-product-schema.sql`; `EXPLAIN` of every statement is in `results/main/diagnosis.json` and uses the intended keys: `PRIMARY`, `uq_maa_product_options_product_code`, `idx_maa_product_variants_product_status_deleted_order`, `uq_maa_product_product_contents_identity`).

Cause, observed (not assumed):

- After the locking reads, each of two transactions on **different** new Products holds a gap/next-key lock on the **supremum** of the Option, Variant and Content indexes (`data_locks`: `X` on `uq_maa_product_options_product_code`, `X` on the variants index, `S` on `uq_maa_product_product_contents_identity`, all `supremum pseudo-record`). These locks do not conflict with each other; both reads returned in ~8 ms with no blocking.
- Each transaction then `INSERT`s into the Content index, which needs an insert-intention lock on exactly that gap, held by the other. A single insert with the other transaction open waited until `innodb_lock_wait_timeout` (1 s in the probe) — proof the insert is blocked by the other transaction's gap lock.
- InnoDB's own report (`LATEST DETECTED DEADLOCK`, in `diagnosis.json`) shows the two inserts, each holding `lock mode S` on the supremum of `uq_maa_product_product_contents_identity` and waiting for `lock_mode X insert intention`; one is rolled back with `1213`/`40001`.

No-retry baseline (policy `none`, one attempt, 5 rounds × 400 ops): **50.0 %** of operations fail with `1213` at 2 connections (every two-product wave loses exactly one transaction; the Issue measured 9 of 20 = 45 %), **87.4 %** at 8 connections. All failures were `1213`; zero other errors in the whole study.

## 3. Results across the design

Pooled over 10 rounds (4000 ops per candidate per cell); "exh." = exhausted operations. Decision cell in bold.

| Cell (placement / mode, connections) | Immediate exh. | Hybrid exh. | rounds Hybrid wins | goodput Imm → Hyb (ops/s) | P95 Imm → Hyb (ms) | Rule outcome |
|---|---:|---:|---:|---:|---:|---|
| sequential / wave, 2 | 0 | 0 | 0 | 179 → 179 | 13.4 → 13.2 | INCONCLUSIVE (nothing to improve) |
| sequential / wave, 4 | 0 | 0 | 0 | 195 → 147 | 23.7 → 30.8 | INCONCLUSIVE (nothing to improve; Hybrid slower) |
| **sequential / wave, 8** | **616** | **10** | **10/10** | **176 → 181** | **48.2 → 50.3** | **HYBRID** |
| sequential / wave, 16 | 1343 | 48 | 10/10 | 177 → 225 | 68.2 → 73.6 | HYBRID |
| shuffled / wave, 2 | 0 | 0 | 0 | 346 → 349 | 7.4 → 7.1 | INCONCLUSIVE |
| shuffled / wave, 8 | 11 | 1 | 0 | 646 → 620 | 15.7 → 16.0 | INCONCLUSIVE (11 < 30 exhausted) |
| shuffled / wave, 16 | 56 | 0 | 8/10 | 706 → 684 | 34.0 → 34.9 | HYBRID |
| sequential / free-run, 4 | 3 | 0 | 0 | 502 → 536 | 12.8 → 9.6 | INCONCLUSIVE |
| sequential / free-run, 8 | 29 | 0 | 3/10 | 645 → 675 | 20.1 → 15.6 | INCONCLUSIVE (29 < 30) |
| sequential / free-run, 16 | 60 | 4 | 7/10 | 825 → 862 | 39.8 → 32.7 | INCONCLUSIVE (not consistent: 7 < 8) |

Only the decision cell counts toward the verdict; the rest are sensitivity. Per-round tables, deadlock counts (original vs repeated), attempts-per-op, P50/P95/P99 and the attempts histogram for every cell are in `results/main/ANALYSIS.md`.

## 4. Interpretation of the differences

- **The original deadlock is identical for both policies** (8 conns: 3498 vs 3499 first-attempt deadlocks) — a retry policy cannot change it; it only changes what happens next.
- **Why Immediate exhausts.** After a victim is rolled back, the other transactions of the same wave are still holding the supremum gap locks and are themselves about to insert. An immediate retry re-takes the same locks within microseconds and walks straight into the same cycle. At 8 connections Immediate needs more than two attempts for 3 of 4 operations that deadlocked and 15 % never get through in four.
- **Why Hybrid helps.** Retry 1 (immediate) usually fails again for the same reason; the jittered waits before retries 2 and 3 let the competing transactions commit and de-synchronise the survivors. Operations that succeed only on attempt 3 rise from 1098 to 2273 (8 conns): Hybrid converts almost every would-be exhaustion into a late success. Repeated deadlocks fall by 38 % (4690 → 2931).
- **What it costs.** Jitter sleeps total 16.2 s of waiting over the 10 rounds at 8 connections (completed-ops/s falls 13 % because Immediate counts fast failures as completed work), P95 rises 2 ms, but successful throughput does not fall because the extra successes outweigh the waiting. Where there is no exhaustion to save (4 connections) the waiting is pure cost: −25 % goodput.
- **2 connections:** every wave has exactly one victim and its first immediate retry always succeeds because the winner has committed. Both policies are identical in behaviour (the jitter never triggers) and the numbers are equal within noise. The Issue's own two-Product case is therefore fully handled by one immediate retry.

## 5. Guarantees (C4)

Verified in every run of both candidates (`integrity_pass` in `analysis.json`; all pass; 0 failures in the log):

- every attempt was one call to the unmodified runner with `PDO::inTransaction()` false before it (and false after each failure — the runner owns and ends the transaction); no retry after a non-`1213` outcome; ≤ 4 attempts;
- in the separate `integrity-verify` runs, `performance_schema.events_transactions_current` showed no `ACTIVE` transaction on the connection before any retry (3,000+ retries probed);
- no `ACTIVE`/`INNODB_TRX` transaction remained after any run; the callback ran exactly once per attempt; no attempt started before the previous one ended plus its jitter sleep; Hybrid sleeps stayed within 0 / 10 ms / 20 ms bounds;
- stored Content rows correspond exactly to successful operations (no duplicates, no missing rows, none for exhausted operations); isolation `REPEATABLE-READ` on every connection;
- client-counted deadlocks equal the server's `lock_deadlocks` delta in every run.

Note: an early version of the per-retry probe read `INFORMATION_SCHEMA.INNODB_TRX`, which is cached for ~100 ms and produced false "open transaction" flags; the probe was replaced by the exact `performance_schema` check before any reported run.

## 6. Limits of the measurement

1. **Contention level decides the winner.** The verdict rests on the declared decision cell (8 simultaneous creators in a synchronised wave, 87 % no-retry failure). That is a worst-case arrival pattern. With barrier-free streaming or shuffled placement at 8 connections Immediate exhausted only 29 and 11 of 4000 operations (below the pre-registered minimum of 30, hence INCONCLUSIVE); Hybrid did at least as well there but the study cannot claim a significant benefit. The benefit becomes clear again at 16 connections (shuffled: 56 → 0, 8/10 rounds).
2. **Hybrid is not free:** −25 % goodput at 4 synchronised connections. A policy decision should weigh whether production traffic resembles the 4-connection region (deadlocks common, exhaustion absent) or the 8+ region.
3. **One lock pattern** (the Issue's) rather than the whole php-product workload; other gap-lock patterns may behave differently.
4. **Environment:** one Docker-Desktop VM on macOS (7.0.14-linuxkit), 8 vCPUs shared by MySQL and up to 16 PHP processes, default durability (`innodb_flush_log_at_trx_commit=1`) on virtualised storage. Absolute latencies/throughputs do not transfer; the paired comparison does. MariaDB and other MySQL versions were not measured.
5. **Metrics:** waves exclude a constant 3 ms release lead from the throughput denominator; goodput was fixed as the throughput criterion after a smoke run (disclosed in METHODOLOGY §6); the "4 of 5 rounds" rule was scaled to 8 of 10; P99 of 400 samples is coarse (pooled P99 over 4000 is reported).
6. **Statistics:** rounds are independent database resets, but operations inside a wave are not independent, so the Newcombe interval understates uncertainty; the 10/10 round consistency is the stronger evidence. No multiple-comparison correction was applied to the sensitivity cells (they carry no verdict).
7. **Out of scope by design:** `1205`, caller-owned transactions and savepoints (no retry there), backoff beyond three retries, and any API design.

## 7. For the architectural decision

The data support Hybrid over Immediate **if** the owner expects bursts where ≥ 8 runner-owned transactions contend for the same gap; they do not show a benefit for the two-Product case in the Issue (one immediate retry already resolves it), and at moderate contention Hybrid's jitter costs throughput. A design that preserves the option of an immediate first retry and bounded jitter afterwards (the evaluated Hybrid) is consistent with every measurement here; a pure Immediate policy is sufficient only if traffic stays below the exhaustion region.
