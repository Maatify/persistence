#!/usr/bin/env bash
# Quiet-host gate (fail-closed). Usage: host-gate.sh <max-wait-seconds> [threshold-percent=200] [required-consecutive=15]
# Passes (exit 0) only after <required-consecutive> consecutive VALID samples below <threshold-percent> of one core.
# An INVALID sample (sensor failure) resets the count and can never pass. Exit 3 on timeout. Prints one summary line.
here="$(cd "$(dirname "$0")" && pwd)"
HQ="${HOST_QUIET_BIN:-$here/host-quiet.sh}"
max="${1:?max wait seconds}"; thr="${2:-200}"; need="${3:-15}"
quiet=0; waited=0; invalid=0; last=none
while [ "$quiet" -lt "$need" ] && [ "$waited" -lt "$max" ]; do
  f=$("$HQ" 2>/dev/null); rc=$?
  if [ "$rc" -ne 0 ] || ! [[ "$f" =~ ^[0-9]+$ ]]; then
    invalid=$((invalid+1)); quiet=0; last=INVALID; sleep 1
  else
    last=$f
    if [ "$f" -lt "$thr" ]; then quiet=$((quiet+1)); else quiet=0; fi
  fi
  waited=$((waited+2))
done
if [ "$quiet" -ge "$need" ]; then echo "PASSED quiet_samples=$quiet invalid_samples=$invalid foreign_cpu_last=${last}%"; exit 0; fi
echo "FAILED quiet_samples=$quiet invalid_samples=$invalid waited~${waited}s foreign_cpu_last=${last}"; exit 3
