# RC-01 / RC-02 experiments

Study material only. Candidate algorithms live in `lib.php`; nothing here is package API and nothing under `src/` changes. Every script creates and drops only `probe_rc01*` objects (tables, the triggers `probe_rc01_fail` / `probe_rc01_audit`, and `maa_example_deadlock_retry_*` tables for the deadlock scripts). Use a disposable database.

## Environments verified by this study

| Engine | Image used | Verified |
|---|---|---|
| MySQL 8.4.10 | `mysql:8.4.10` | every script below |
| MariaDB 11.8.9 | `mariadb:11` | every script except `locks.php` and `round2_scaling.php` (they read `performance_schema.data_locks`, which MariaDB does not have) |

PHP 8.4.4 with `pdo_mysql` (mysqlnd). Other engines, versions and PHP versions are **not** verified.

## Requirements

* `composer install` at the repository root (the scripts load `vendor/autoload.php`).
* A user with `CREATE`, `DROP`, `ALTER`, `INSERT/UPDATE/DELETE/SELECT`, **`TRIGGER`** on the test database, and `KILL` on other sessions of the same user (only `dg03/round2.php`, scenario D).
* MySQL only: `SET GLOBAL log_bin_trust_function_creators = 1;` when binary logging is on (needed to create triggers without `SUPER`), and `SELECT` on `performance_schema` for `locks.php` and `round2_scaling.php`.
* `ext-pdo_mysql`, `ext-zlib` is not needed. `proc_open` must be available (`concurrency.php`, deadlock scripts).

Disposable servers:

```bash
docker run -d --rm --name study-mysql -e MYSQL_ROOT_PASSWORD=rootpw -e MYSQL_DATABASE=probe -p 127.0.0.1:33999:3306 mysql:8.4.10
docker run -d --rm --name study-mariadb -e MARIADB_ROOT_PASSWORD=rootpw -e MARIADB_DATABASE=probe -p 127.0.0.1:33998:3306 mariadb:11
# MySQL, once it accepts connections:
docker exec -e MYSQL_PWD=rootpw study-mysql mysql -uroot -e "SET GLOBAL log_bin_trust_function_creators = 1"
```

```bash
export PERSISTENCE_TEST_MYSQL_USER=root PERSISTENCE_TEST_MYSQL_PASSWORD=rootpw
export PERSISTENCE_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=33999;dbname=probe;charset=utf8mb4'   # MySQL
# export PERSISTENCE_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=33998;dbname=probe;charset=utf8mb4' # MariaDB
cd docs/studies/full-fresh-review
```

## Round 1 (RC-01 / RC-02)

```bash
php rc01/matrix.php                      # RED/GREEN matrix, ~1 min; compare MySQL vs MariaDB with diff
php rc01/atomicity.php                   # partial failure vs savepoint restore
php rc01/stale_snapshot_park.php         # parking with an older REPEATABLE READ snapshot
php rc01/locks.php                       # MySQL only
php rc01/concurrency.php 4 150 'B2 park(locked max)+ORDER BY' unique   # args: workers moves candidate unique|nonunique
```

## Round 2 (F45-R01 .. R05)

```bash
php rc01/round2_park_slot.php            # R01: soft-deleted slot, INT boundaries (B2, BS2, B3, BS3), failure at the parking write
php rc01/round2_race.php                 # R01: plain writer racing the parking slot, RR vs RC
php rc01/round2_side_effects.php         # R02: audit triggers (InnoDB + MyISAM), updated_at
php rc01/round2_scaling.php              # R04: lock counts / latency vs scope size (MySQL only; sizes up to 100000, a few minutes)
php rc01/engine_snapshot.php             # R05: shipped code under an older snapshot, per engine
php dg03/experiments.php                 # DG-03 E1..E5
php dg03/round2.php                      # R03: DO 0, autocommit=0, savepoint detection, failing refresh
for i in 1 2 3 4 5; do php rc01/concurrency.php 4 100 'S0 shipped' nonunique; php rc01/concurrency.php 4 100 'BS3 B3+savepoint' unique; done   # repeated 1213 runs
```

Captured outputs sit next to the scripts (`*-mysql.txt`, `*-mariadb11.txt`, `experiments-output.txt`, `matrix-output*.txt`). `mariadb11-experiments-truncated.txt` holds the MariaDB runs of the other scripts, cut to 150 lines per script. `concurrency.php` results vary between runs by design; the repeats file records the observed range, not a ranking.
