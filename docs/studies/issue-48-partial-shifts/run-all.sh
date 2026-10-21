#!/usr/bin/env bash
# Re-runs the whole Issue #48 study into results/ and then asserts the expected RED / GREEN outcomes.
# Needs the engines from engines-up.sh. Non-zero on any probe crash or unmet expectation; nothing is ignored.
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p results
clean() { sed -e '/^PHP /d' -e '/Deprecated/d' -e '/^$/d'; }
for subject in real recipe proto nested; do
  ./run-matrix.sh matrix.php "$subject" 2>&1 | clean > "results/matrix-$subject.txt"
done
./run-matrix.sh s4_differential.php 2>&1 | clean > results/s4-differential.txt
./run-matrix.sh s5_locks.php 2>&1 | clean > results/s5-locks.txt
./assert-results.sh
