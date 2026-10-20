#!/usr/bin/env bash
# Data directories are tmpfs (throwaway, and no Docker-volume disk is consumed). Starts the throwaway engines used by the Issue #47 study (ports 33381-33384; MySQL 8.4.10 is the repository's CI
# container on 33340, started separately exactly as CONTRIBUTING/CI describe). Credentials are the CI test credentials.
set -euo pipefail
start_mariadb() { # image port name
  docker rm -fv "p47-$3" >/dev/null 2>&1 || true
  docker run -d --name "p47-$3" -p "127.0.0.1:$2:3306" --tmpfs /var/lib/mysql:rw,size=512m -e MARIADB_ROOT_PASSWORD=root_ci_password \
    -e MARIADB_DATABASE=maatify_persistence_test -e MARIADB_USER=maatify_persistence -e MARIADB_PASSWORD=persistence_ci_password "$1" >/dev/null
}
start_mariadb mariadb:11 33381 mdb118          # 11.8.9 at study time (docker tag "11" -> 11.8.9)
start_mariadb mariadb:11.4.7 33382 mdb114
start_mariadb mariadb:10.11 33383 mdb1011      # 10.11.18 at study time
docker rm -fv p47-mysql80 >/dev/null 2>&1 || true
docker run -d --name p47-mysql80 -p 127.0.0.1:33384:3306 --tmpfs /var/lib/mysql:rw,size=512m \
  -e MYSQL_ROOT_PASSWORD=root_ci_password -e MYSQL_DATABASE=maatify_persistence_test -e MYSQL_USER=maatify_persistence \
  -e MYSQL_PASSWORD=persistence_ci_password mysql:8.0.46 --innodb-dedicated-server=OFF --innodb-redo-log-capacity=100M >/dev/null
sleep 40
