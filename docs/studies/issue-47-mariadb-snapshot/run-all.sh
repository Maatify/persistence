#!/usr/bin/env bash
# Re-runs the whole Issue #47 study into results/. Needs the engines from ENGINES in run-matrix.sh (default ports there).
set -uo pipefail
cd "$(dirname "$0")"
mkdir -p results
export ENGINES=${ENGINES:-"mysql-8.0.46=33384 mysql-8.4.10=33340 mariadb-10.11=33383 mariadb-11.4.7=33382 mariadb-11.8.9=33381"}
clean() { grep -v '^PHP \|Deprecated\|^$'; }
{ ./run-matrix.sh p1_reproduce.php default ON OFF 2>&1 | clean; } > results/p1-reproduce.txt
{ ./run-matrix.sh p2_root_cause.php default 2>&1 | clean; } > results/p2-root-cause.txt
{ ./run-matrix.sh p3_error_fate.php default ON 2>&1 | clean; } > results/p3-error-fate.txt
{ ./run-matrix.sh p4_propagation.php default 2>&1 | clean; } > results/p4-propagation.txt
{ ./run-matrix.sh p5_remedies.php default 2>&1 | clean; } > results/p5-remedies.txt
{ ./run-matrix.sh p6_race.php default 2>&1 | clean; } > results/p6-race-control.txt
{ ./run-matrix.sh p7_statement_scope.php default 2>&1 | clean; } > results/p7-statement-scope.txt
{ PROBE_SRC="${V140_SRC:-}" ./run-matrix.sh p1_reproduce.php default 2>&1 | clean; } > results/p1-reproduce-v1.4.0.txt 2>&1 || true
