#!/usr/bin/env bash
# Samples, every ~2-4 s, the CPU used by everything outside this study run (host-quiet.sh v2) and the 1-minute load
# average. Output line: <ISO time UTC> foreign=<percent of one core> load1=<value> v=2
here="$(cd "$(dirname "$0")" && pwd)"
while true; do
  f=$("$here/host-quiet.sh")
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) foreign=${f:-0} load1=$(sysctl -n vm.loadavg | awk '{print $2}') v=2"
done
