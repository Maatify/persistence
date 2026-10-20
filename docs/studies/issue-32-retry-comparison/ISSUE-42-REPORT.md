# Issue #42 — isolated-host C2/C3 confirmation: report

Study only ([Issue #42](https://github.com/Maatify/persistence/issues/42), follow-up to
[Issue #32](https://github.com/Maatify/persistence/issues/32) and merged [PR #41](https://github.com/Maatify/persistence/pull/41)).
No change to `src/`, `tests/`, the public API, the harness, the decision rule ([`METHODOLOGY.md`](METHODOLOGY.md) §6) or
runs 1–5. This report presents evidence; it does not choose a retry default or authorise an implementation.

Order of work (all on 2026-10-20, UTC): Phase A host qualification → frozen plan committed (`b88f47b`,
[`ISSUE-42-PLAN.md`](ISSUE-42-PLAN.md)) → run-6 → run-7 → analysis. Exactly the two pre-planned runs were executed;
no run was repeated, replaced or dropped.

## 1. Result

**C2/C3 ACCEPTED under controlled conditions** — scope: this workload (php-product first-Content creation), MySQL 8.0.46,
the qualified host (§4), the synchronized 8-worker sequential-wave cell. Both pre-planned runs are valid and clean, and
**each** primary cell passes C1, C2, C3 and C4. This is not a global performance guarantee for the package.

Primary cell (`sequential-wave`, 8 connections, 10 balanced paired rounds × 400 ops per candidate, unprobed):

| | run-6 (A) Immediate | run-6 (A) Hybrid | run-7 (B) Immediate | run-7 (B) Hybrid |
|---|---:|---:|---:|---:|
| Exhausted (4 attempts, all `1213`) | 634 / 4000 (15.85 %) | **2 / 4000 (0.05 %)** | 638 / 4000 (15.95 %) | **11 / 4000 (0.27 %)** |
| Rounds won by Hybrid on C1 | | **10 / 10** (need 8) | | **10 / 10** |
| 95 % CI of Δ exhausted (Hybrid − Immediate) | | [−16.97, −14.69] pp | | [−16.85, −14.55] pp |
| Goodput (successful ops / active s) | 192.0 | 189.5 | 191.3 | 189.8 |
| Latency P50 / P95 / P99, all ops (ms) | 27.97 / 39.08 / 45.37 | 28.90 / 46.84 / 61.04 | 28.26 / 39.32 / 44.31 | 28.90 / 47.15 / 59.29 |
| Original / repeated deadlocks | 3500 / 4721 | 3500 / 2896 | 3500 / 4772 | 3499 / 2940 |

| Criterion (threshold unchanged) | run-6 (A) | run-7 (B) |
|---|---|---|
| **C1** exhaustion (≥ 20 % rel. and ≥ 1 pp, 8/10 rounds, CI excludes 0) | **PASS** (Δ 15.80 pp, 99.7 % rel.) | **PASS** (Δ 15.68 pp, 98.3 % rel.) |
| **C2** goodput(Hybrid) ≥ 0.95 × goodput(Immediate) | **PASS** — ratio **0.987** (limit 0.950; margin 0.037) | **PASS** — ratio **0.992** (margin 0.042) |
| **C3** P95(Hybrid) ≤ P95(Immediate) + max(10 %, 10 ms) | **PASS** — Δ **+7.76 ms**, allowance 10.00 ms (margin 2.24 ms) | **PASS** — Δ **+7.83 ms**, allowance 10.00 ms (margin 2.17 ms) |
| **C4** guarantees (every run, both candidates) | **PASS** | **PASS** |
| Sample sufficient (≥ 5 rounds, ≥ 200 ops, ≥ 30 Immediate exhausted) | yes | yes |
| Host: runs flagged noisy (cell) | 0 / 20 | 0 / 20 |
| Rule outcome | `HYBRID` | `HYBRID` |

The allowance is 10 ms in both runs because 10 % of Immediate's P95 (≈ 3.9 ms) is smaller than 10 ms.

### Probed sensitivity cell (`sequential-wave-probed`, `--verify=1`) — reported separately, carries no verdict

| | run-6 (A) | run-7 (B) |
|---|---|---|
| Exhausted Immediate → Hybrid | 610 → 10 (10/10 rounds) | 646 → 5 (10/10 rounds) |
| Goodput Immediate → Hybrid (ratio) | 179.5 → 181.0 (1.009) | 182.1 → 182.4 (1.002) |
| P95 Immediate → Hybrid (Δ / allowance) | 42.18 → 47.95 ms (+5.77 / 10.00) | 41.31 → 49.35 ms (+8.04 / 10.00) |
| C1–C4 / noisy | all pass / 0 of 20 | all pass / 0 of 20 |

The earlier probed counter-signal (run-3 `integrity-verify` +10.31 ms, run-4 probed +10.92 ms, both just over 10 ms)
was **not reproduced** on this host (+5.77, +8.04 ms). It remains part of the record: it came from a host whose
cleanliness was not certified, so neither set of numbers overrides the other.

## 2. What else the data show (no criterion; for the owner)

- **The cost is real and close to the tolerance.** Hybrid's P95 is +7.8 ms in both runs (≈ +20 %), with about 2.2 ms
  of margin to the C3 limit; per round the P95 difference ranges from −1.5 ms to **+17.1 ms**, and **4 of 20 rounds
  exceed 10 ms** (run-6 r1 +17.05; run-7 r1 +12.98, r2 +13.25, r10 +10.22). C3 is defined on the pooled P95, which
  passes. P99 rises ~+15 ms (45 → 61, 44 → 59 ms). Goodput cost is ~1 %. Jitter sleeps total ~16.3 s per 4000 Hybrid
  operations.
- **Why it is cheap here:** Hybrid rescues 632 (run-6) / 627 (run-7) of the operations Immediate exhausts; successes on
  attempt 3 roughly double (1087 → 2300, 1096 → 2289) while successes on attempt 4 fall (866 → 295, 881 → 309), and
  repeated deadlocks drop ~38 % (4721 → 2896, 4772 → 2940). The first-attempt deadlock is identical for both
  (3500/3499 of 4000): a retry policy cannot change it.
- **The two runs agree closely** (exhaustion 634/638 vs 2/11; goodput ratio 0.987/0.992; ΔP95 7.76/7.83 ms), so the
  pre-planned pair does not contradict itself.

## 3. Integrity and guarantee evidence (C4)

- Analyzer: both directories validated fail-closed (40/40 declared files each, counts/outcomes/ids consistent, one
  harness hash `69959875…` = run-5's, one MySQL version 8.0.46), `load.json` recomputed from the raw `host-load.log`;
  `analyze.php --dir=…` exits 0 for both.
- Every run: zero `error`, zero `invalid` operations; client `1213` count = server `lock_deadlocks` delta (80/80 runs;
  29 248 and 29 398 deadlocks); isolation `REPEATABLE-READ`; rows ⇔ successful operations; no open transaction after
  any failure or run (probed cell: none before any retry either); preflight observer positive controls pass
  (`preflight/*.json`); harness self-tests 63/63 in each run (`selftest.txt`), including the observer negative control.
- Independent re-check from the raw files without the analyzer ([`raw-recheck.php`](raw-recheck.php),
  [`results/i42-raw-recheck.txt`](results/i42-raw-recheck.txt)): 32 000 operations — 0 bad outcomes, 0 operations with
  more than 4 attempts, 0 retries after anything other than `1213`+`40001`, 0 non-zero Immediate sleeps, 0 Hybrid sleeps
  outside [0] / [0, 10 ms] / [0, 20 ms], 0 raw/client/server deadlock mismatches; it fails (exit 1) on tampered copies.

## 4. Host evidence

| | run-6 (A) | run-7 (B) |
|---|---|---|
| Quiet gate (fail-closed, 15 consecutive valid samples < 200 %) | PASSED, 0 invalid, last 62 % | PASSED, 0 invalid, last 66 % |
| Host samples / `INVALID` samples | 71 / 0 | 71 / 0 |
| Noisy runs (mean > 200 % or peak > 400 % or no sample) | **0 / 40** | **0 / 40** |
| Foreign CPU in run windows: mean of run means / highest run mean / highest peak — primary cell | 120 / 166 / 188 % | 118 / 182 / 224 % |
| Same — probed cell | 131 / 192 / 210 % | 125 / 194 / 210 % |
| Containers running | study container only | study container only |
| Power (`host-processes.log`) | battery 72 % → 68 %, Low Power Mode off throughout | battery 68 % → 64 %, Low Power Mode off throughout |

What the foreign CPU consisted of (`host-processes.log`, per-process): Docker Desktop's macOS-side networking
(`com.docker.backend`, ~35 % mean, up to ~73 %, carrying the study's own MySQL traffic but outside the process tree, so
counted conservatively as foreign), `WindowServer` (~25 %), `kernel_task`, Docker Desktop helper, the sampler/sidecar
`top`, and the executor's idle desktop session. No other project, test suite or container appeared. The highest single
sample (373 % in run-7, 07:39:18Z) fell before the first measured run (gate/self-test phase) and outside every run window.

**Limits of the host evidence, stated plainly:** each measured run lasts ~2–3 s, so it overlaps only 1–2 sensor samples
(this is the existing design; no run had zero samples); the highest per-run means (192 / 194 % in probed runs) are close
to the pre-registered 200 % limit, mostly because Docker's own networking counts as foreign; Phase A is a point-in-time
qualification of an owner's personal machine with idle background services, not a dedicated server.

## 5. Comparison with PR #41 (runs 1–5, unchanged)

| Run (decision cell, unprobed) | Host | Immediate → Hybrid exhausted | Goodput ratio | ΔP95 | Host evidence | Outcome |
|---|---|---:|---:|---:|---|---|
| run-1 | runs 1–5 laptop | 616 → 10 | 1.029 | +2.1 ms | not recorded | HYBRID |
| run-2 | same | 503 → 12 | 1.468 | −154 ms | contaminated | unreliable |
| run-3 | same | 542 → 5 | 0.961 | +7.5 ms | load-average only, gate timed out | HYBRID (not certified) |
| run-4 | same | 537 → 15 | 1.022 | +2.2 ms | superseded name-based attribution | not certified |
| run-5 | same | 428 → 43 | 1.104 | −10.4 ms | process-tree, 14/20 noisy | EVIDENCE REQUIRED |
| **run-6 (A)** | **independent Mac** | **634 → 2** | **0.987** | **+7.76 ms** | process-tree v3, **0/20 noisy** | **HYBRID — clean** |
| **run-7 (B)** | **independent Mac** | **638 → 11** | **0.992** | **+7.83 ms** | process-tree v3, **0/20 noisy** | **HYBRID — clean** |

- Exhaustion: seven of seven measurements now show the same 10/10-round effect. Immediate's exhaustion is a little
  higher here (15.9 % vs 10.7–15.4 %).
- Cost: run-3, the closest earlier run in conditions, measured goodput ratio 0.961 and ΔP95 +7.5 ms; the clean runs give
  0.987 / 0.992 and +7.8 ms. The P95 cost is reproduced at almost the same size; the goodput cost is smaller.
- Hosts differ (Phase A record, [`results/i42-phase-a-independent-mac/HOST-QUALIFICATION.md`](results/i42-phase-a-independent-mac/HOST-QUALIFICATION.md)):
  same SoC (Apple M1, 8 cores), same 8-vCPU Docker VM and linuxkit kernel, same MySQL image digest and harness hash;
  but PHP 8.4.4 vs 8.5.9, macOS 26.5.2 vs Darwin 27, Docker VM memory 7.65 vs 7.75 GiB, and battery power (earlier power
  state not recorded). Absolute figures are therefore not directly comparable; the paired comparison is.

## 6. Limitations that remain (unchanged by this result)

1. Scope: one lock pattern, one synchronized 8-worker burst cell, one MySQL version, one host class (Apple M1 laptop,
   Docker Desktop VM, virtualised storage). Not a production or MariaDB measurement.
2. 4-connection goodput cost of Hybrid (~−27 % in run-3, where there was no exhaustion to rescue) was not re-measured
   here (outside the `decision` profile) and remains a documented limitation; so do the inconclusive less-synchronized
   cells (free-run, shuffled).
3. The C3 margin is ~2.2 ms and single rounds exceed 10 ms; P99 cost is ~+15 ms.
4. Host evidence limits in §4 (1–2 samples per run; run means up to 194 % vs a 200 % limit; personal machine).
5. Statistical caveats of METHODOLOGY / REPORT §6 (operations within a wave are not independent) still apply.

## 7. For the owner

The open item of PR #41 — performance acceptance under controlled host load — now has two clean, mutually consistent
measurements in which Hybrid passes C2 and C3 at the decision cell. Whether Hybrid becomes behaviour, opt-in or
configurable, whether the moderate-contention cost matters, and whether Issue #32 can be closed are decisions for the
owner; this study does not make them.

## Files

- Plan: [`ISSUE-42-PLAN.md`](ISSUE-42-PLAN.md). Phase A: [`results/i42-phase-a-independent-mac/`](results/i42-phase-a-independent-mac/).
- Runs: [`results/run-6-i42-decision-a/`](results/run-6-i42-decision-a/), [`results/run-7-i42-decision-b/`](results/run-7-i42-decision-b/)
  — each with `runs/*.jsonl.gz` (per-operation and per-attempt raw data), `manifest.json`, `environment.json`,
  `preflight/`, `selftest.txt`, `quiet-gate.txt`, `host-load.log`, `load.json`, `LOAD.md`, `host-processes.log`,
  `analysis.json`, `ANALYSIS.md`, `run.log`.
- Independent re-check: [`raw-recheck.php`](raw-recheck.php), [`results/i42-raw-recheck.txt`](results/i42-raw-recheck.txt).
- Repository gates: [`results/i42-local-gates.txt`](results/i42-local-gates.txt).
