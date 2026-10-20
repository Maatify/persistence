#!/usr/bin/env bash
# host-quiet v2 — prints the CPU used (percent of ONE core, averaged over ~2 s) by everything that does NOT belong to
# this study run. 100 = one core fully busy; the study host has 8 vCPUs.
#
# Attribution is by process TREE, never by name: "own" = STUDY_ROOT_PID (set by run-all.sh; fallback: the parent of this
# script) and all of its descendants (workers, PHP, sleep, bash, top, host-load...). A process called worker.php, bash or
# php that is NOT a descendant (another project's test run, say) counts as foreign.
# MySQL runs in the Docker Desktop VM: the VM process is not counted by name; instead the CPU of the study container
# (STUDY_DOCKER_CONTAINER) is own and the CPU of every OTHER container is foreign (docker stats). If that cannot be
# determined, the whole VM process is counted as foreign (conservative).
ROOT="${STUDY_ROOT_PID:-$PPID}"
own=$(ps -Ao pid=,ppid= | awk -v root="$ROOT" -v me="$$" '
  { p[$1] = $2; ids[NR] = $1 }
  END {
    own[root] = 1; own[me] = 1; changed = 1
    while (changed) { changed = 0; for (i = 1; i <= NR; i++) { id = ids[i]; if (!(id in own) && (p[id] in own)) { own[id] = 1; changed = 1 } } }
    for (k in own) printf "%s ", k
  }')

# Debug/test hook: print the attributed own pids and stop (used by harness/selftest.php).
if [ "${HOST_QUIET_PRINT_OWN:-0}" = "1" ]; then echo "$own"; exit 0; fi

docker_foreign=""
if [ -n "${STUDY_DOCKER_CONTAINER:-}" ] && command -v docker >/dev/null 2>&1; then
  docker_foreign=$(docker stats --no-stream --format '{{.Name}} {{.CPUPerc}}' 2>/dev/null | awk -v own="$STUDY_DOCKER_CONTAINER" '$1 != own { gsub("%", "", $2); s += $2 } END { printf "%.0f", s }')
  [ -z "$docker_foreign" ] && docker_foreign=""
fi

top -l 2 -s 2 -stats pid,command,cpu -o cpu -n 120 2>/dev/null | awk -v own="$own" -v dockerForeign="$docker_foreign" '
  BEGIN { n = split(own, a, " "); for (i = 1; i <= n; i++) mine[a[i]] = 1 }
  /^PID/ { blk++; next }
  blk == 2 && /^[0-9]/ {
    if ($1 in mine) next
    if ($2 ~ /^com\.apple\.Virtua/) { if (dockerForeign == "") f += $NF; next }
    f += $NF
  }
  END { if (dockerForeign != "") f += dockerForeign; printf "%.0f\n", f }'
