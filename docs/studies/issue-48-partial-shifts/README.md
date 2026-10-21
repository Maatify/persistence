# Issue #48 operation-local savepoint study — how to re-run

Study-only material for [Issue #48](https://github.com/Maatify/persistence/issues/48). Nothing under `src/`, `tests/`, `composer.json` or the public docs changes; the probes are not autoloaded, analysed or formatted by the repository gates. Read [`REPORT.md`](REPORT.md) first.

| File | Purpose |
|---|---|
| [`REPORT.md`](REPORT.md) | Exact base, RED/GREEN evidence, error taxonomy, public-contract impact matrix, the recommended design, limits and Owner questions |
| `SavepointMovePrototype.php` | The recommended design as a standalone study class (`Maatify\Persistence\Study48`); **not** package runtime |
| `lib.php` | Shared scaffolding: schema, seed, subjects (`real`, `recipe`, `proto`, `nested`, `reuse`), `FaultyPdo` (injected control-statement failures), the per-cell observation |
| `matrix.php` | The failure matrix (cells C01–C15, S1–S7, O1–O3) for one subject |
| `deadlock_partner.php` | Child process for the real `1213` deadlock (cell C10/C15) |
| `s4_differential.php` | Differential parity of the shipped call and the prototype over seeded random operations |
| `s5_locks.php` | Lock footprint after `ROLLBACK TO SAVEPOINT`, failed and successful moves |
| `engines-up.sh`, `run-matrix.sh`, `run-all.sh` | Start the throwaway engines, run one probe over the engine matrix, run everything into `results/` and assert |
| `assert-results.sh` | Independent checks of the captured results (list and result: `results/assert-output.txt`): RED where expected (shipped call), GREEN where expected (prototype), not-rescuable classes, locks |
| [`ADDENDUM-1-FINAL-DESIGN.md`](ADDENDUM-1-FINAL-DESIGN.md) | Final design per the Lead decision: released-`v1.4.0` vs `main` evidence, fix of the existing `moveWithinScope()`, savepoint reuse strategy, updated compatibility matrix, test/doc/release requirements |
| `results/` | Captured output of the run described in the REPORT, `env.txt` (engines and digests), the existing-suite comparison and `d1-adapter.diff` |

## Re-run

```bash
docs/studies/issue-48-partial-shifts/engines-up.sh      # MySQL 8.4.10 (33440), the same with rollback_on_timeout=ON (33441), MySQL 8.0.46 (33442), MariaDB 11.8.9 (33481)
composer install
mkdir -p /tmp/v140 && git archive v1.4.0 src | tar -x -C /tmp/v140     # released-tag source for the Addendum A1 comparison
V140_SRC=/tmp/v140 docs/studies/issue-48-partial-shifts/run-all.sh          # exits non-zero if any probe fails or an expected outcome is missing
```

A single subject on a single engine:

```bash
PERSISTENCE_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=33440;dbname=maatify_persistence_test' \
PERSISTENCE_TEST_MYSQL_USER=maatify_persistence PERSISTENCE_TEST_MYSQL_PASSWORD=persistence_ci_password \
php docs/studies/issue-48-partial-shifts/matrix.php real          # real | recipe | proto | nested, optional cell-id prefixes after it
```

The existing-suite comparison in `results/phpunit-existing-suite.txt` was produced in a scratch copy of the repository: `vendor/bin/phpunit` on the unmodified base, then again after applying `results/d1-adapter.diff` (routes `moveWithinScope()` to the prototype whenever a transaction is active; `SavepointMovePrototype.php` is copied to `src/Study48/` in that scratch copy only). The repository integration tests need `SET GLOBAL log_bin_trust_function_creators = 1`, exactly as the CI does.
