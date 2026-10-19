#!/usr/bin/env bash
# Re-runs the whole Issue #32 study into a FRESH output directory (the harness refuses to mix runs into an existing one).
# Requires a MySQL 8.0.46 server (see README.md) and the STUDY_MYSQL_* variables.
set -euo pipefail
cd "$(dirname "$0")/harness"
OUT="${1:?usage: run-all.sh <fresh-output-dir, relative to harness/>}"
[ -e "$OUT/manifest.json" ] && { echo "refusing to reuse $OUT" >&2; exit 2; }
mkdir -p "$OUT"

ROOT="$(cd .. && pwd)"
# Wait (max 30 min) until the host is quiet: 1-minute load average below 6 for 15 consecutive 2 s samples. (Other
# sessions/tests on a shared machine otherwise distort latency and throughput; see METHODOLOGY section 8.)
quiet=0; waited=0
while [ "$quiet" -lt 15 ] && [ "$waited" -lt 1800 ]; do
  load=$(sysctl -n vm.loadavg | awk '{printf "%d", $2}')
  if [ "$load" -lt 6 ]; then quiet=$((quiet+1)); else quiet=0; fi
  sleep 2; waited=$((waited+2))
done
echo "host quiet gate: quiet_samples=$quiet waited=${waited}s load1_now=$(sysctl -n vm.loadavg)" | tee "$OUT/quiet-gate.txt"
"$ROOT/host-load.sh" > "$OUT/host-load.log" &
SAMPLER=$!
trap 'kill $SAMPLER 2>/dev/null || true' EXIT

php study.php env      --out="$OUT"
php selftest.php --with-db=1 | tee "$OUT/selftest.txt"
php study.php diagnose --out="$OUT"

# Decision cells (pre-registered): sequential placement, barrier waves, 2/4/8/16 connections, 10 rounds x 400 ops per candidate.
php study.php run --out="$OUT" --tag=sequential-wave --placement=sequential --mode=wave --levels=2,4,8,16 --rounds=10 --ops=400

# Reference: how often the Issue's scenario deadlocks with no retry at all (one attempt).
php study.php run --out="$OUT" --tag=baseline-none --placement=sequential --mode=wave --levels=2,8 --rounds=5 --ops=400 --policies=none

# Guarantee runs with the extra server-side "no open transaction before each retry" probe (kept separate so it cannot perturb timing).
php study.php run --out="$OUT" --tag=integrity-verify --placement=sequential --mode=wave --levels=8 --rounds=5 --ops=400 --verify=1

# Sensitivity: shuffled Product placement (some gaps bounded by neighbours) and barrier-free streaming.
php study.php run --out="$OUT" --tag=shuffled-wave --placement=shuffled --mode=wave --levels=2,8,16 --rounds=10 --ops=400
php study.php run --out="$OUT" --tag=sequential-free --placement=sequential --mode=free --levels=4,8,16 --rounds=10 --ops=400

kill $SAMPLER 2>/dev/null || true
php "$ROOT/load-report.php" "$OUT"
php analyze.php --dir="$OUT"
