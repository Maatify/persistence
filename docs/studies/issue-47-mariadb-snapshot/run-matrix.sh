#!/usr/bin/env bash
# Runs one probe across the engine matrix. Usage: run-matrix.sh p1_reproduce.php [default|ON|OFF ...]
# Engines are the throwaway containers listed in ENGINES (name=port); credentials are the CI test credentials.
# Any probe crash or unreachable engine is a hard failure (non-zero exit).
set -euo pipefail
cd "$(dirname "$0")"
PROBE="$1"; shift
MODES=("${@:-default}")
ENGINES=${ENGINES:-"mysql-8.0.46=33384 mysql-8.4.10=33340 mariadb-10.11=33383 mariadb-11.4.7=33382 mariadb-11.8.9=33381"}
for e in $ENGINES; do
  for m in "${MODES[@]}"; do
    # MySQL has no such variable: one pass is enough
    if [[ "$e" == mysql-* && "$m" != "${MODES[0]}" ]]; then continue; fi
    PERSISTENCE_TEST_MYSQL_DSN="mysql:host=127.0.0.1;port=${e#*=};dbname=maatify_persistence_test" \
    PERSISTENCE_TEST_MYSQL_USER=maatify_persistence PERSISTENCE_TEST_MYSQL_PASSWORD=persistence_ci_password \
    PROBE_SNAPSHOT_ISOLATION="$m" php -d display_errors=stderr -d xdebug.mode=off "$PROBE"
  done
done
