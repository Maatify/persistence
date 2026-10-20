#!/usr/bin/env bash
# Issue #42 side evidence (macOS). NOT part of the harness, its hash or the analyzer's classification: every ~10 s it
# records the processes that used >= 3 % of one core over a 1 s `top` interval (with parent pid), plus the power source,
# battery level and Low Power Mode, so the foreign CPU in host-load.log can be attributed to concrete processes and a
# change of power conditions during a run is visible. It runs OUTSIDE the study process tree, so its own small cost is
# counted as foreign CPU (conservative). Usage: ./host-processes.sh > <results-dir>/host-processes.log
while true; do
  power=$(pmset -g batt 2>/dev/null | awk -F"'" 'NR == 1 { print $2 }')
  batt=$(pmset -g batt 2>/dev/null | awk 'NR == 2 { print $3 $4 }')
  lpm=$(pmset -g 2>/dev/null | awk '$1 == "lowpowermode" { print $2 }')
  echo "== $(date -u +%Y-%m-%dT%H:%M:%SZ) load1=$(sysctl -n vm.loadavg | awk '{print $2}') power=\"${power}\" battery=${batt} lowpowermode=${lpm}"
  top -l 2 -s 1 -stats pid,ppid,command,cpu -o cpu -n 12 2>/dev/null | awk '/^PID/ { b++; next } b == 2 && $NF + 0 >= 3 { sub(/ +$/, ""); print }'
  sleep 9
done
