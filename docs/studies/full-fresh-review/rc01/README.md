# RC-01 / RC-02 experiments

Study material only. Candidate algorithms live in `lib.php`; nothing here is package API and nothing under `src/` changes.

```bash
export PERSISTENCE_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=disposable_db;charset=utf8mb4'
export PERSISTENCE_TEST_MYSQL_USER=... PERSISTENCE_TEST_MYSQL_PASSWORD=...
composer install
php docs/studies/full-fresh-review/rc01/matrix.php            # RED/GREEN matrix (about 1 minute)
php docs/studies/full-fresh-review/rc01/atomicity.php         # partial failure, savepoint restore
php docs/studies/full-fresh-review/rc01/stale_snapshot_park.php
php docs/studies/full-fresh-review/rc01/locks.php             # needs SELECT on performance_schema
php docs/studies/full-fresh-review/rc01/concurrency.php 4 150 'B2 park(locked max)+ORDER BY' unique
```

The scripts create and drop only `probe_rc01` (and the trigger `probe_rc01_fail`). Use a disposable database.
