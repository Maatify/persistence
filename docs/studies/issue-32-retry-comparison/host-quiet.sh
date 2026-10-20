#!/usr/bin/env bash
# host-quiet v3 — prints the CPU used (percent of ONE core, averaged over ~2 s) by everything that does NOT belong to
# this study run. 100 = one core fully busy; the study host has 8 vCPUs.
#
# FAIL-CLOSED: if the sample cannot be taken or parsed, nothing numeric is printed: the script prints "INVALID" to
# stdout, the reason to stderr, and exits 1. A broken sensor is never reported as 0 %.
#
# Attribution is by process TREE, never by name: "own" = STUDY_ROOT_PID (set by run-all.sh; fallback: the parent of this
# script) and all of its descendants. MySQL runs in the Docker Desktop VM: the study container's CPU
# (STUDY_DOCKER_CONTAINER) is own, every OTHER container's CPU is foreign (docker stats). If docker stats fails, prints
# nothing usable, or does not list the study container, the whole VM process is counted as foreign (conservative).
#
# Test hooks (harness/selftest.php): HOST_QUIET_TOP_CMD / HOST_QUIET_DOCKER_CMD replace `top` / `docker`,
# HOST_QUIET_PRINT_OWN=1 prints the attributed own pids and stops.
ROOT="${STUDY_ROOT_PID:-$PPID}"
TOP="${HOST_QUIET_TOP_CMD:-top}"
DOCKER="${HOST_QUIET_DOCKER_CMD:-docker}"

invalid() { echo "host-quiet: $1" >&2; echo INVALID; exit 1; }

own=$(ps -Ao pid=,ppid= | awk -v root="$ROOT" -v me="$$" '
  { p[$1] = $2; ids[NR] = $1 }
  END {
    own[root] = 1; own[me] = 1; changed = 1
    while (changed) { changed = 0; for (i = 1; i <= NR; i++) { id = ids[i]; if (!(id in own) && (p[id] in own)) { own[id] = 1; changed = 1 } } }
    for (k in own) printf "%s ", k
  }')
[ -n "$own" ] || invalid "could not determine own process tree"

if [ "${HOST_QUIET_PRINT_OWN:-0}" = "1" ]; then echo "$own"; exit 0; fi

docker_foreign=""
if [ -n "${STUDY_DOCKER_CONTAINER:-}" ]; then
  dstats=$("$DOCKER" stats --no-stream --format '{{.Name}} {{.CPUPerc}}' 2>/dev/null); drc=$?
  # Usable only if the command succeeded AND listed the study container with a numeric CPU value.
  if [ "$drc" -eq 0 ] && echo "$dstats" | awk -v c="$STUDY_DOCKER_CONTAINER" '$1 == c && $2 ~ /^[0-9.]+%$/ { ok = 1 } END { exit ok ? 0 : 1 }'; then
    docker_foreign=$(echo "$dstats" | awk -v own="$STUDY_DOCKER_CONTAINER" '$1 != own { v = $2; gsub("%", "", v); if (v ~ /^[0-9.]+$/) s += v } END { printf "%.0f", s }')
  fi
fi

sample=$("$TOP" -l 2 -s 2 -stats pid,command,cpu -o cpu -n 120 2>/dev/null); trc=$?
[ "$trc" -eq 0 ] || invalid "top exited with status $trc"
# A usable sample has two process tables with numeric rows; the second one is the 2-second interval.
rows=$(echo "$sample" | awk '/^PID/ { blk++; next } blk == 2 && $1 ~ /^[0-9]+$/ && $NF ~ /^[0-9.]+$/ { n++ } END { print n + 0 }')
[ "${rows:-0}" -ge 5 ] || invalid "top produced no usable second sample (rows=${rows:-0})"

echo "$sample" | awk -v own="$own" -v dockerForeign="$docker_foreign" '
  BEGIN { n = split(own, a, " "); for (i = 1; i <= n; i++) mine[a[i]] = 1 }
  /^PID/ { blk++; next }
  blk == 2 && $1 ~ /^[0-9]+$/ && $NF ~ /^[0-9.]+$/ {
    if ($1 in mine) next
    if ($2 ~ /^com\.apple\.Virtua/) { if (dockerForeign == "") f += $NF; next }
    f += $NF
  }
  END { if (dockerForeign != "") f += dockerForeign; printf "%.0f\n", f }'
