#!/usr/bin/env bash
# Runs the Issue #32 study into a FRESH output directory (the harness refuses to mix runs into an existing one).
# Works against a genuinely empty MySQL 8.0.46 database: the schema is created by the harness itself.
#
#   ./run-all.sh <fresh-dir relative to harness/> [profile]
#     profile  full      the complete design (default, ~25 min)
#              decision  targeted repeat of the 8-connection decision cell only (+ a probed replicate), ~8 min
#              smoke     1 round x 8 ops at 2 and 8 connections; proves the pipeline end to end from an empty database
#
# Environment: STUDY_MYSQL_DSN / _USER / _PASSWORD, STUDY_DOCKER_CONTAINER (optional, recorded), ALLOW_NOISY=1 to
# proceed when the host never became quiet (recorded as OVERRIDDEN; noisy runs are then flagged in the analysis),
# QUIET_GATE=off (smoke profile only).
set -euo pipefail
cd "$(dirname "$0")/harness"
OUT="${1:?usage: run-all.sh <fresh-output-dir, relative to harness/> [full|decision|smoke]}"
PROFILE="${2:-full}"
[ -e "$OUT/manifest.json" ] && { echo "refusing to reuse $OUT" >&2; exit 2; }
mkdir -p "$OUT"
ROOT="$(cd .. && pwd)"
# Process-tree attribution of host load (host-quiet.sh v2): everything below this shell is the study, the rest is foreign.
export STUDY_ROOT_PID=$$

# Quiet-host gate (fail-closed, host-gate.sh): foreign CPU (host-quiet.sh v3, process-tree attribution) below 200% of one core
# (25% of the 8 vCPUs) for 15 consecutive VALID samples, within 40 minutes; a failing sensor never passes. Otherwise nothing
# is started (exit 3) unless ALLOW_NOISY=1.
if [ "${QUIET_GATE:-on}" = "off" ] && [ "$PROFILE" = "smoke" ]; then
  echo "host quiet gate: OFF (smoke profile)" | tee "$OUT/quiet-gate.txt"
else
  grc=0
  gate=$("$ROOT/host-gate.sh" "${GATE_MAX_WAIT:-2400}" 200 15) || grc=$?
  echo "host quiet gate: $gate load1=$(sysctl -n vm.loadavg)" | tee "$OUT/quiet-gate.txt"
  if [ "$grc" -ne 0 ]; then
    if [ "${ALLOW_NOISY:-0}" != "1" ]; then
      echo "host never became quiet (or the sensor failed): not starting (set ALLOW_NOISY=1 to override)" >&2
      exit 3
    fi
    echo "OVERRIDDEN by ALLOW_NOISY=1" | tee -a "$OUT/quiet-gate.txt"
  fi
fi

"$ROOT/host-load.sh" > "$OUT/host-load.log" &
SAMPLER=$!
trap 'kill $SAMPLER 2>/dev/null || true; pkill -P $SAMPLER 2>/dev/null || true' EXIT

php study.php env --out="$OUT"
php selftest.php --with-db=1 --with-host=1 | tee "$OUT/selftest.txt"

case "$PROFILE" in
  smoke)
    php study.php run --out="$OUT" --tag=smoke --placement=sequential --mode=wave --levels=2,8 --rounds=1 --ops=8
    ;;
  decision)
    # Targeted repeat of the pre-registered decision cell (8 connections, sequential, waves, 10 rounds x 400 ops).
    php study.php run --out="$OUT" --tag=sequential-wave --placement=sequential --mode=wave --levels=8 --rounds=10 --ops=400
    # Same cell with the per-retry server-side probe, to isolate the probe's own cost (cf. integrity-verify C3 counter-signal).
    php study.php run --out="$OUT" --tag=sequential-wave-probed --placement=sequential --mode=wave --levels=8 --rounds=10 --ops=400 --verify=1
    ;;
  full)
    php study.php diagnose --out="$OUT"
    php study.php run --out="$OUT" --tag=sequential-wave --placement=sequential --mode=wave --levels=2,4,8,16 --rounds=10 --ops=400
    php study.php run --out="$OUT" --tag=baseline-none --placement=sequential --mode=wave --levels=2,8 --rounds=5 --ops=400 --policies=none
    php study.php run --out="$OUT" --tag=integrity-verify --placement=sequential --mode=wave --levels=8 --rounds=5 --ops=400 --verify=1
    php study.php run --out="$OUT" --tag=shuffled-wave --placement=shuffled --mode=wave --levels=2,8,16 --rounds=10 --ops=400
    php study.php run --out="$OUT" --tag=sequential-free --placement=sequential --mode=free --levels=4,8,16 --rounds=10 --ops=400
    ;;
  *) echo "unknown profile $PROFILE" >&2; exit 2 ;;
esac

kill $SAMPLER 2>/dev/null || true
php "$ROOT/load-report.php" "$OUT"
php analyze.php --dir="$OUT"
