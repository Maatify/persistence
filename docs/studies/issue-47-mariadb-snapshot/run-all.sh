#!/usr/bin/env bash
# Re-runs the whole Issue #47 study into results/ and then asserts the expected outcomes.
# Needs the engines from run-matrix.sh (engines-up.sh) and V140_SRC=<dir containing the v1.4.0 src/> (see README).
# Fails (non-zero) on any probe crash, missing engine, missing V140_SRC or unmet expectation; nothing is ignored.
set -euo pipefail
cd "$(dirname "$0")"
: "${V140_SRC:?set V140_SRC to a directory holding the v1.4.0 src/ (see README)}"
[[ -d "$V140_SRC/src/Pdo/Ordering" ]] || { echo "V140_SRC has no src/Pdo/Ordering" >&2; exit 2; }
mkdir -p results
export ENGINES=${ENGINES:-"mysql-8.0.46=33384 mysql-8.4.10=33340 mariadb-10.11=33383 mariadb-11.4.7=33382 mariadb-11.8.9=33381"}
clean() { sed -e '/^PHP /d' -e '/Deprecated/d' -e '/^$/d'; }
probe() { # outfile probe modes... ; pipefail makes a failing probe fail the script
  local out="$1"; shift
  ./run-matrix.sh "$@" 2>&1 | clean > "results/$out"
}
probe p1-reproduce.txt p1_reproduce.php default ON OFF
probe p2-root-cause.txt p2_root_cause.php default
probe p3-error-fate.txt p3_error_fate.php default ON
probe p4-propagation.txt p4_propagation.php default
probe p5-remedies.txt p5_remedies.php default
probe p6-race-control.txt p6_race.php default
probe p7-statement-scope.txt p7_statement_scope.php default
PROBE_SRC="$V140_SRC" probe p1-reproduce-v1.4.0.txt p1_reproduce.php default
./assert-results.sh
