# Issue #42 — frozen Phase B plan (committed before measuring)

> **Distribution note:** the raw-data directory `results/` referenced below is excluded from Composer/GitHub package archives (`.gitattributes` `export-ignore`, Issue #60) to keep the installed package small. It is unchanged in the Git repository: [browse `results/`](https://github.com/Maatify/persistence/tree/e4aba2043fe0956d7097621d22dfb23e4efbbcfa/docs/studies/issue-32-retry-comparison/results). Relative `results/...` links work in GitHub's repository web view and in a full Git checkout, but are dead in a Composer `--prefer-dist` installation or any extracted package archive. Commands in this document that read or write raw data need a full Git checkout (or an independently obtained copy of `results/`).

Scope: [Issue #42](https://github.com/Maatify/persistence/issues/42) — controlled-host confirmation of C2 (goodput) and C3
(P95) for [Issue #32](https://github.com/Maatify/persistence/issues/32). Study only: no change to `src/`, `tests/`, the
public API, the harness (`harness/`), the decision rule ([`METHODOLOGY.md`](METHODOLOGY.md) §6) or runs 1–5.

Prerequisite: Phase A passed on an independent Mac —
[`results/i42-phase-a-independent-mac/HOST-QUALIFICATION.md`](results/i42-phase-a-independent-mac/HOST-QUALIFICATION.md).

## 1. What is run

Exactly two complete, independent executions of the existing `decision` profile, in this order, on the qualified host,
against the same `mysql:8.0.46` container (`maatify-i32-mysql`, not restarted between runs; the harness drops and
recreates the database before every candidate/round):

| Order | Results directory (new) | Command (from the study directory) |
|---|---|---|
| 1 | `results/run-6-i42-decision-a` | `STUDY_DOCKER_CONTAINER=maatify-i32-mysql ./run-all.sh ../results/run-6-i42-decision-a decision` |
| 2 | `results/run-7-i42-decision-b` | `STUDY_DOCKER_CONTAINER=maatify-i32-mysql ./run-all.sh ../results/run-7-i42-decision-b decision` |

Each `decision` run contains, unchanged: the fail-closed quiet gate (`host-gate.sh 2400 200 15`), `host-load.sh` sampling
for the whole run, `study.php env`, `selftest.php --with-db=1 --with-host=1`, the **primary unprobed cell**
`sequential-wave` (8 connections, sequential placement, barrier waves, 10 balanced paired rounds × 400 operations per
candidate, Immediate vs Hybrid, max 4 attempts, `1213`+`40001` classifier) and the **probed sensitivity cell**
`sequential-wave-probed` (same, `--verify=1`), then `load-report.php` and the fail-closed `analyze.php`.

Additionally, outside the study process tree, `host-processes.sh` writes `host-processes.log` into each results directory
(per-process attribution of foreign CPU, power source, battery, Low Power Mode). It is side evidence only; it does not
enter the harness hash or the analyzer's classification.

Not used: `QUIET_GATE=off`, `ALLOW_NOISY=1`, `--allow-legacy=1`, `--resume=1`, any other profile, cell, parameter or
additional repetition.

## 2. Stop conditions (decided now)

Run 2 starts after run 1 has finished, **whatever run 1's C1–C3 outcome is**. Work stops — no further measurement, no
replacement run, evidence kept as is and reported for review — if any of the following happens in a run:

1. the quiet gate fails (exit 3) or the sensor reports `INVALID`;
2. `load.json` / the analyzer flags any run of either cell as noisy, or the analyzer refuses the evidence;
3. any C4 guarantee check fails (`error`/`invalid` operation, open transaction, attempt/sleep bounds, row reconciliation,
   client/server deadlock mismatch, preflight failure);
4. the power conditions change during a run (adapter connected, Low Power Mode on) or a competing workload appears
   (another container, test suite or project process visible in `host-processes.log`);
5. a self-test fails.

## 3. Disposition (Issue #42, applied per run and to the pair; thresholds from METHODOLOGY §6, unchanged)

- C2: pooled goodput(Hybrid) ≥ 0.95 × goodput(Immediate). C3: pooled P95(Hybrid) ≤ P95(Immediate) + max(10 % × P95(Immediate), 10 ms), all operations.
- **C2/C3 ACCEPTED under controlled conditions** only if both runs are valid and clean and **each** primary cell passes
  C2 and C3 together with C1 and C4 — scoped to this workload, MySQL 8.0.46, this host and the synchronized 8-worker cell.
- **C2/C3 FAILED** if a valid clean primary cell violates either threshold (exact numbers reported, even if other runs favour Hybrid).
- **EVIDENCE REQUIRED / INCONCLUSIVE** if host isolation, sensor integrity, observer coverage or sample sufficiency
  fails, or the two valid runs contradict each other.
- Both runs are reported individually; the probed cell is reported separately and never substitutes for the primary
  cell. The earlier probed P95 counter-signal (+10.31 / +10.92 ms) and the 4-connection goodput cost (~27 %) remain
  documented limitations regardless of the outcome. No policy, default or API decision is made here.
