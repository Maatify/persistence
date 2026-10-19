#!/usr/bin/env bash
# Samples, every ~2 s, the CPU used by processes outside the study (host-quiet.sh) and the 1-minute load average.
# Output line: <ISO time UTC> foreign=<percent of one core> load1=<value>
here="$(cd "$(dirname "$0")" && pwd)"
while true; do
  f=$("$here/host-quiet.sh")
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) foreign=${f:-0} load1=$(sysctl -n vm.loadavg | awk '{print $2}')"
done
