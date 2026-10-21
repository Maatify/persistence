#!/usr/bin/env bash
# Runs one probe over the engine matrix. Usage: run-matrix.sh <probe.php> [probe args...]
# ENGINES is a list of name=port; the cell set of matrix.php decides which cells are meaningful per engine.
set -euo pipefail
cd "$(dirname "$0")"
ENGINES=${ENGINES:-"mysql-8.0.46=33442 mysql-8.4.10=33440 mysql-8.4.10-rollback-on-timeout=33441 mariadb-11.8.9=33481"}
for e in $ENGINES; do
  echo "#### ${e%%=*}"
  PERSISTENCE_TEST_MYSQL_DSN="mysql:host=127.0.0.1;port=${e#*=};dbname=maatify_persistence_test" \
  PERSISTENCE_TEST_MYSQL_USER=maatify_persistence PERSISTENCE_TEST_MYSQL_PASSWORD=persistence_ci_password \
  php -d display_errors=stderr -d xdebug.mode=off "$@"
done
