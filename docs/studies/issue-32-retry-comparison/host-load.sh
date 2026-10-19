#!/usr/bin/env bash
# Samples the host 1-minute load average every 2 s (other sessions/tests on the same machine inflate it; the study's
# own contribution is at most connections + 2 runnable threads).
# Output line: <ISO time UTC> <load1>
while true; do
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $(sysctl -n vm.loadavg | awk '{print $2}')"
  sleep 2
done
