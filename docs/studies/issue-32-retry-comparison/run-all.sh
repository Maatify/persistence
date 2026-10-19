#!/usr/bin/env bash
# Re-runs the whole Issue #32 study. Requires a MySQL 8.0.46 server (see README.md) and the STUDY_MYSQL_* variables.
set -euo pipefail
cd "$(dirname "$0")/harness"
OUT="${1:-../results/main}"

php study.php env      --out="$OUT"
php study.php diagnose --out="$OUT"

# Decision cells (pre-registered): sequential placement, barrier waves, 2/4/8/16 connections, 10 rounds x 400 ops per candidate.
php study.php run --out="$OUT" --tag=sequential-wave --placement=sequential --mode=wave --levels=2,4,8,16 --rounds=10 --ops=400

# Reference: how often the Issue's scenario deadlocks with no retry at all (one attempt).
php study.php run --out="$OUT" --tag=baseline-none --placement=sequential --mode=wave --levels=2,8 --rounds=5 --ops=400 --policies=none

# Guarantee runs with the extra server-side "no open transaction before each retry" probe (kept separate so it cannot perturb timing).
php study.php run --out="$OUT" --tag=integrity-verify --placement=sequential --mode=wave --levels=8 --rounds=5 --ops=400 --verify=1

# Sensitivity: shuffled Product placement (some gaps bounded by neighbours) and barrier-free streaming.
php study.php run --out="$OUT" --tag=shuffled-wave --placement=shuffled --mode=wave --levels=2,8,16 --rounds=10 --ops=400
php study.php run --out="$OUT" --tag=sequential-free --placement=sequential --mode=free --levels=4,8,16 --rounds=10 --ops=400

php analyze.php --dir="$OUT"
