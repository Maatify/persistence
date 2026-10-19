#!/usr/bin/env bash
# Prints the CPU use (percent of ONE core, averaged over a 2 s interval) of every process that does not belong to the
# study. Own = harness PHP processes (pids found via pgrep), this script, top/awk, and the Docker VM that runs MySQL.
# 100 = one core fully busy; the study host has 8 vCPUs.
ours=$( { pgrep -f 'worker\.php|study\.php|selftest\.php|run-all\.sh|host-load\.sh|host-quiet\.sh'; echo $$; echo $PPID; } | tr '\n' ' ')
top -l 2 -s 2 -stats pid,command,cpu -o cpu -n 80 2>/dev/null | awk -v ours="$ours" '
  BEGIN { n = split(ours, a, " "); for (i = 1; i <= n; i++) own[a[i]] = 1 }
  /^PID/ { blk++; next }
  blk == 2 && /^[0-9]/ {
    if (($1 in own) || $2 ~ /^(top|awk|com\.apple\.Virtua|pgrep|bash|sleep)/) next
    f += $NF
  }
  END { printf "%.0f\n", f }'
