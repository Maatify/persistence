# Issue #32 retry-policy study — how to re-run

> **Distribution note:** the raw-data directory `results/` referenced below is excluded from Composer/GitHub package archives (`.gitattributes` `export-ignore`, Issue #60) to keep the installed package small. It is unchanged in the Git repository: [browse `results/`](https://github.com/Maatify/persistence/tree/e4aba2043fe0956d7097621d22dfb23e4efbbcfa/docs/studies/issue-32-retry-comparison/results). Relative `results/...` links work in GitHub's repository web view and in a full Git checkout, but are dead in a Composer `--prefer-dist` installation or any extracted package archive. Commands in this document that read or write raw data need a full Git checkout (or an independently obtained copy of `results/`).

Study-only material for [Issue #32](https://github.com/Maatify/persistence/issues/32). It is **not** production runtime or public API, and it does not change package runtime or consumer contracts: nothing under `src/`, `tests/`, `composer.json` or the public docs changes, no Retry feature is implemented, and the harness is not autoloaded, analysed or formatted by the repository gates.

| File | Purpose |
|---|---|
| [`REPORT.md`](REPORT.md) | Evidence report: scenario, results, interpretation, limits, recommendation |
| [`METHODOLOGY.md`](METHODOLOGY.md) | Pre-registered design, metrics, guarantee checks and decision rule |
| `harness/lib.php` | Scenario SQL, retry policies, retry executor (wraps the unmodified runner) |
| `harness/worker.php` | One worker process = one independent PDO connection |
| `harness/study.php` | Orchestrator: `run`, `diagnose`, `env` |
| `harness/diagnose.php` | `EXPLAIN`, `data_locks`, blocked insert, InnoDB deadlock report |
| `harness/analyze.php` | Fail-closed evidence validation, metrics, tables, pre-registered decision (constants at the top) |
| `harness/selftest.php` | Negative tests of the harness itself (retry classification, ownership, evidence validation, reuse guard, observer sensitivity) |
| `schema/php-product-schema.sql` | Verbatim `maatify/php-product` schema (see METHODOLOGY §3) |
| `run-all.sh` | `full` / `decision` / `smoke` profile into a fresh directory; fail-closed quiet-host gate; works from an empty database |
| `host-quiet.sh` (v3), `host-gate.sh`, `host-load.sh`, `load-report.php`, `harness/loadlib.php` | Fail-closed foreign-CPU measurement (process-tree attribution; a failed sensor is `INVALID`, never 0 %), the quiet-host gate and the per-run noisy-host flags; classification shared with the analyzer |
| `results/run-1-initial/` | First complete run (earlier harness version, legacy format; reconstructed manifest) |
| `results/run-2/` | Re-run with the final harness; a later part overlapped heavy load from another project on the same host (see REPORT §6) |
| `results/run-3/` | Re-run with the final harness (primary for the full design; its quiet gate timed out, see REPORT §6) |
| `results/run-4-decision-cell/` | Targeted repeat of the 8-connection decision cell (name-based host attribution, superseded; legacy host evidence) |
| `results/run-5-decision-cell/` | Corrected targeted repeat (process-tree attribution); the host became busy, 34/40 runs flagged → performance `EVIDENCE REQUIRED` |
| `results/f41-04-fresh-database-proof.txt`, `results/f41-06-local-gates.txt` | Review evidence: run from an empty database; full local repository gates |
| [`ISSUE-42-REPORT.md`](ISSUE-42-REPORT.md), [`ISSUE-42-PLAN.md`](ISSUE-42-PLAN.md) | Issue #42 isolated-host C2/C3 confirmation: report and the plan frozen before measuring |
| `results/i42-phase-a-independent-mac/` | Issue #42 Phase A: host identity/qualification, real-container sensor attribution control, self-tests, smoke and sensor-under-load checks (no C2/C3 data) |
| `results/run-6-i42-decision-a/`, `results/run-7-i42-decision-b/` | Issue #42: the two pre-planned `decision` profile runs on the qualified independent Mac; 0/40 runs noisy each |
| `host-processes.sh`, `raw-recheck.php`, `results/i42-raw-recheck.txt`, `results/i42-local-gates.txt` | Issue #42 side evidence (per-process foreign-CPU and power log, outside the harness hash), analyzer-independent raw re-check, repository gates |

Each results directory holds `runs/*.jsonl.gz`, `manifest.json`, `environment.json`, `preflight/`, `diagnosis.json`, `analysis.json`, `ANALYSIS.md`, `run.log` (run-2/3 also `selftest.txt`; run-3 `host-load.log`, `LOAD.md`, `quiet-gate.txt`).

## Requirements

PHP ≥ 8.2 with `pdo_mysql` and `zlib` (CLI), Docker, `git`. No Composer install is needed (the harness autoloads `src/` itself, or uses `vendor/` when present).

## Re-run

```bash
docker run -d --name maatify-i32-mysql -e MYSQL_ROOT_PASSWORD=study_root -e MYSQL_DATABASE=i32_study \
  -e MYSQL_USER=i32 -e MYSQL_PASSWORD=i32_pass -p 127.0.0.1:33320:3306 mysql:8.0.46
# wait until `docker exec maatify-i32-mysql mysqladmin ping -h 127.0.0.1 -uroot -pstudy_root` answers, then:
docker exec maatify-i32-mysql mysql -uroot -pstudy_root \
  -e "GRANT ALL ON *.* TO 'i32'@'%'; GRANT SELECT ON performance_schema.* TO 'i32'@'%';"
```

```bash
cd docs/studies/issue-32-retry-comparison
STUDY_DOCKER_CONTAINER=maatify-i32-mysql ./run-all.sh ../results/rerun full        # fresh dir, relative to harness/; ~25 min (+ quiet-host wait)
STUDY_DOCKER_CONTAINER=maatify-i32-mysql ./run-all.sh ../results/rerun-8 decision  # only the 8-connection decision cell, ~10 min
QUIET_GATE=off ./run-all.sh ../results/smoke smoke                                 # pipeline check from an empty database, ~1 min
```

The connection can be changed with `STUDY_MYSQL_DSN`, `STUDY_MYSQL_USER`, `STUDY_MYSQL_PASSWORD` (the user needs `DROP/CREATE DATABASE` and read access to `performance_schema` and `information_schema`; the harness **drops and recreates the database named in the DSN** on every run — point it only at a disposable server).

Single cell **with the host evidence the analyzer requires** (collection only is not enough: `analyze.php` refuses evidence without a verified `host-load.log`/`load.json`):

```bash
cd docs/studies/issue-32-retry-comparison
bash -euo pipefail <<'SCRIPT'            # stops at the first failure; do not paste the lines into a shell without -e/pipefail
export STUDY_ROOT_PID=$$ STUDY_DOCKER_CONTAINER=maatify-i32-mysql      # host attribution by process tree
OUT=results/try                                                         # fresh directory
mkdir -p "$OUT"
# fail-closed gate: exit 3 (not quiet / sensor failed) aborts the whole script; its verdict is kept in quiet-gate.txt
./host-gate.sh 2400 200 15 > "$OUT/quiet-gate.txt" || { cat "$OUT/quiet-gate.txt"; echo "host not quiet: not measuring" >&2; exit 3; }
cat "$OUT/quiet-gate.txt"
./host-load.sh > "$OUT/host-load.log" & SAMPLER=$!
trap 'kill $SAMPLER 2>/dev/null || true' EXIT
(cd harness && php study.php run --out="../$OUT" --tag=sequential-wave --levels=8 --rounds=2 --ops=400)
kill $SAMPLER; trap - EXIT
php load-report.php "$OUT"                                              # writes load.json / LOAD.md
php harness/analyze.php --dir="$OUT"                                    # refuses incomplete/inconsistent evidence (exit 3)
SCRIPT
php harness/selftest.php --with-db=1 --with-host=1                      # harness self-tests (--with-db needs the study MySQL)
```

Collection-only (no host evidence, for debugging the harness; `analyze.php` will refuse it unless `--allow-legacy=1`, and its numbers must not be used for performance claims): `cd harness && php study.php run --out=../results/try --tag=sequential-wave --levels=8 --rounds=2 --ops=400`. `run-all.sh <dir> decision` does all of the above in one command.

Absolute timings depend on the host; the pass/fail pattern of the paired comparison is what should reproduce. Re-analysis of committed raw data needs no database: `php harness/analyze.php --dir=results/run-5-decision-cell` (run-1…run-4 predate parts of the evidence format or host attribution and need `--allow-legacy=1`).

## Reading the raw data

`runs/<tag>_c<connections>_r<round>_<policy>.jsonl.gz`: one JSON object per operation (`type":"op"`) and a final `type":"summary"` line.

- `attempts`: list of `[start_us_from_release, duration_us, error_code (0 = committed, -1 = non-PDO), sleep_after_us, sqlstate]` (run-1 has no `sqlstate`)
- `outcome`: `ok`, `exhausted` (all attempts deadlock), `error` (anything else) or `invalid` (ownership precondition failed); the last two must be zero
- `latency_us`: first attempt start → last attempt end, jitter sleeps included
- summary: counts, `active_us`, per-wave `wave_active_us`, server deadlock counter delta, integrity block

Quick look: `zcat results/run-3/runs/sequential-wave_c08_r01_hybrid.jsonl.gz | head`.
