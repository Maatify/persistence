# Issue #47 MariaDB snapshot-isolation study — how to re-run

Study-only material for [Issue #47](https://github.com/Maatify/persistence/issues/47). Nothing under `src/`, `tests/`, `composer.json` or the public docs changes; the probes are not autoloaded, analysed or formatted by the repository gates. Read [`REPORT.md`](REPORT.md) first.

| File | Purpose |
|---|---|
| [`REPORT.md`](REPORT.md) | Evidence, root cause, options, the recommendation and the open Owner questions |
| `lib.php` | Shared probe scaffolding (CI env variables, `PROBE_SNAPSHOT_ISOLATION`, `PROBE_SRC` to probe another source tree such as the `v1.4.0` tag) |
| `p1_reproduce.php` | RED: shipped `getNextPositionForUpdate()` / `moveWithinScope()` after a stale snapshot, plus the package-owned control |
| `p2_root_cause.php` | Statement shape × concurrent change matrix: exactly which SQL raises `1020` |
| `p3_error_fate.php` | What `1020` does to the caller's transaction (server-side abort vs `PDO::inTransaction()`) |
| `p4_propagation.php` | Propagation through the shipped runners; deadlock-retry runner does not replay `1020` |
| `p5_remedies.php` | Host-side remedies R1–R6 against the same scenario |
| `p6_race*.php` | Control: ordinary queueing concurrency yields `1213`, not `1020` |
| `p7_statement_scope.php` | Feasibility evidence for MariaDB `SET STATEMENT ... FOR` (rejected route, see REPORT §7) |
| `engines-up.sh`, `run-matrix.sh`, `run-all.sh` | Start the engines (tmpfs data dirs), run one probe across the matrix, run everything into `results/` and assert the expected outcomes (`assert-results.sh`) |
| `assert-results.sh` | Independent checks of the captured results: RED where expected, GREEN where expected, v1.4.0 affected, no replay |
| `results/` | Captured output of the run described in the REPORT, incl. `phpunit-*.txt` summaries of the repository integration suite |

## Re-run

```bash
docs/studies/issue-47-mariadb-snapshot/engines-up.sh        # MariaDB 11.8.9 / 11.4.7 / 10.11.18 and MySQL 8.0.46
# MySQL 8.4.10 is the repository CI container on 127.0.0.1:33340 (see CONTRIBUTING.md / .github/workflows/ci.yml)
mkdir -p /tmp/v140 && git archive v1.4.0 src | tar -x -C /tmp/v140   # required: the released-tag probe
V140_SRC=/tmp/v140 docs/studies/issue-47-mariadb-snapshot/run-all.sh   # exits non-zero if any probe fails or an expected RED/GREEN outcome is missing
```

A single probe on a single engine:

```bash
PERSISTENCE_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=33381;dbname=maatify_persistence_test' \
PERSISTENCE_TEST_MYSQL_USER=maatify_persistence PERSISTENCE_TEST_MYSQL_PASSWORD=persistence_ci_password \
PROBE_SNAPSHOT_ISOLATION=default php docs/studies/issue-47-mariadb-snapshot/p1_reproduce.php   # default | ON | OFF
```

The repository suite on MariaDB (RED/GREEN comparison) is the unchanged `vendor/bin/phpunit --testsuite integration` with the same three environment variables; to flip the engine setting use `docker exec p47-mdb118 mariadb -uroot -proot_ci_password -e 'SET GLOBAL innodb_snapshot_isolation=OFF'`.
