# Issue #32 retry-policy study — how to re-run

Study-only material for [Issue #32](https://github.com/Maatify/persistence/issues/32). It is **not** part of the package: nothing under `src/`, `tests/`, `composer.json` or the public docs changes, no Retry feature is implemented, and the harness is not autoloaded, analysed or formatted by the repository gates.

| File | Purpose |
|---|---|
| [`REPORT.md`](REPORT.md) | Evidence report: scenario, results, interpretation, limits, recommendation |
| [`METHODOLOGY.md`](METHODOLOGY.md) | Pre-registered design, metrics, guarantee checks and decision rule |
| `harness/lib.php` | Scenario SQL, retry policies, retry executor (wraps the unmodified runner) |
| `harness/worker.php` | One worker process = one independent PDO connection |
| `harness/study.php` | Orchestrator: `run`, `diagnose`, `env` |
| `harness/diagnose.php` | `EXPLAIN`, `data_locks`, blocked insert, InnoDB deadlock report |
| `harness/analyze.php` | Metrics, tables, pre-registered decision (constants at the top) |
| `schema/php-product-schema.sql` | Verbatim `maatify/php-product` schema (see METHODOLOGY §3) |
| `run-all.sh` | Whole study, in order |
| `results/main/` | Raw results (`runs/*.jsonl.gz`), `environment.json`, `diagnosis.json`, `analysis.json`, `ANALYSIS.md`, `run.log` |

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
STUDY_DOCKER_CONTAINER=maatify-i32-mysql ./run-all.sh ../results/rerun   # relative to harness/; ~25 minutes
```

The connection can be changed with `STUDY_MYSQL_DSN`, `STUDY_MYSQL_USER`, `STUDY_MYSQL_PASSWORD` (the user needs `DROP/CREATE DATABASE` and read access to `performance_schema` and `information_schema`; the harness **drops and recreates the database named in the DSN** on every run — point it only at a disposable server).

Single cells:

```bash
cd docs/studies/issue-32-retry-comparison/harness
php study.php run --out=../results/try --tag=sequential-wave --levels=8 --rounds=2 --ops=400
php analyze.php --dir=../results/try
```

Absolute timings depend on the host; the pass/fail pattern of the paired comparison is what should reproduce. Re-analysis of the committed raw data needs no database: `php harness/analyze.php --dir=results/main`.

## Reading the raw data

`runs/<tag>_c<connections>_r<round>_<policy>.jsonl.gz`: one JSON object per operation (`type":"op"`) and a final `type":"summary"` line.

- `attempts`: list of `[start_us_from_release, duration_us, error_code (0 = committed, 1213 = deadlock), sleep_after_us]`
- `outcome`: `ok`, `exhausted` (all attempts 1213) or `error` (anything else; must be zero)
- `latency_us`: first attempt start → last attempt end, jitter sleeps included
- summary: counts, `active_us`, per-wave `wave_active_us`, server deadlock counter delta, integrity block

Quick look: `zcat results/main/runs/sequential-wave_c08_r01_hybrid.jsonl.gz | head`.
