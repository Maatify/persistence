#!/usr/bin/env bash
# Throwaway engines for the Issue #48 study (tmpfs data dirs, ports 33440-33442 and 33481), CI test credentials.
# MySQL 8.4.10 on 33440 is the repository CI engine; 33441 is the same image with innodb_rollback_on_timeout=ON
# (cell C07/C08 variant); 33442 MySQL 8.0.46; 33481 MariaDB 11.8.9 (innodb_snapshot_isolation=ON by default).
# log_bin_trust_function_creators=1 mirrors the CI step "Configure MySQL trigger creation policy" (the cells use triggers
# as deterministic fault injection).
set -euo pipefail
run() { # name port image [extra server args...]
  local name="$1" port="$2" image="$3"; shift 3
  docker rm -fv "p48-$name" >/dev/null 2>&1 || true
  local env=(-e MYSQL_ROOT_PASSWORD=root_ci_password -e MYSQL_DATABASE=maatify_persistence_test -e MYSQL_USER=maatify_persistence -e MYSQL_PASSWORD=persistence_ci_password)
  [[ "$image" == mariadb* ]] && env=(-e MARIADB_ROOT_PASSWORD=root_ci_password -e MARIADB_DATABASE=maatify_persistence_test -e MARIADB_USER=maatify_persistence -e MARIADB_PASSWORD=persistence_ci_password)
  docker run -d --name "p48-$name" -p "127.0.0.1:$port:3306" --tmpfs /var/lib/mysql:rw,size=512m "${env[@]}" "$image" --log-bin-trust-function-creators=1 "$@" >/dev/null
}
run mysql84 33440 mysql:8.4.10
run mysql84rot 33441 mysql:8.4.10 --innodb-rollback-on-timeout=ON
run mysql80 33442 mysql:8.0.46 --innodb-dedicated-server=OFF --innodb-redo-log-capacity=100M
run mdb118 33481 mariadb:11.8.9
for port in 33440 33441 33442 33481; do
  for _ in $(seq 1 60); do
    php -r 'try{new PDO("mysql:host=127.0.0.1;port='$port';dbname=maatify_persistence_test","maatify_persistence","persistence_ci_password");exit(0);}catch(Throwable){exit(1);}' && break
    sleep 3
  done
done
