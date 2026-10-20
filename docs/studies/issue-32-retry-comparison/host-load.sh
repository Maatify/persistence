#!/usr/bin/env bash
# Samples, every ~2-4 s, the CPU used by everything outside this study run (host-quiet.sh v3) and the 1-minute load
# average. Output line: <ISO time UTC> foreign=<percent of one core | INVALID> load1=<value> v=2
# A failed sensor is written as foreign=INVALID (never as 0); the analysis treats any run overlapping such a sample as
# not clean.
here="$(cd "$(dirname "$0")" && pwd)"
HQ="${HOST_QUIET_BIN:-$here/host-quiet.sh}"
while true; do
  f=$("$HQ" 2>/dev/null); rc=$?
  if [ "$rc" -ne 0 ] || ! [[ "$f" =~ ^[0-9]+$ ]]; then f=INVALID; fi
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) foreign=${f} load1=$(sysctl -n vm.loadavg | awk '{print $2}') v=2"
  [ "${HOST_LOAD_ONCE:-0}" = "1" ] && break
done
