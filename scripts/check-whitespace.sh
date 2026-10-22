#!/usr/bin/env bash
# Whitespace gate: verifies the whole committed tree (HEAD against the empty
# tree), then any local staged, unstaged and untracked changes. A bare
# `git diff --check` on a clean checkout compares nothing and always passes.
# Verbatim evidence that must keep its bytes is exempted by exact file name with
# a `-whitespace` attribute in the .gitattributes next to it (no wildcards).
# Any Git failure fails the gate; no error is discarded.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

status=0
empty_tree="$(git hash-object -t tree /dev/null)"
untracked_list="$(mktemp)"
errors="$(mktemp)"
trap 'rm -f "$untracked_list" "$errors"' EXIT

echo "Checking committed tree (HEAD against the empty tree)"
git diff --check "$empty_tree" HEAD || status=1

echo "Checking staged changes"
git diff --cached --check || status=1

echo "Checking unstaged changes"
git diff --check || status=1

echo "Checking untracked files"
git ls-files --others --exclude-standard -z > "$untracked_list"
while IFS= read -r -d '' file; do
  # --no-index exits 0 or 1 when the file is clean (1 means "differs") and
  # 2 or 3 when --check reports a whitespace defect; anything else, or any
  # stderr output, is a Git failure and fails the gate.
  code=0
  report="$(git diff --check --no-index /dev/null "$file" 2> "$errors")" || code=$?
  if [[ -s "$errors" ]]; then
    echo "git diff failed for ${file}:" >&2
    cat "$errors" >&2
    status=1
  elif [[ "$code" -eq 0 || "$code" -eq 1 ]]; then
    [[ -z "$report" ]] || { printf '%s\n' "$report"; status=1; }
  elif [[ "$code" -eq 2 || "$code" -eq 3 ]]; then
    printf '%s\n' "$report"
    status=1
  else
    echo "git diff exited with ${code} for ${file}" >&2
    status=1
  fi
done < "$untracked_list"

if [[ "$status" -ne 0 ]]; then
  echo "Whitespace verification failed." >&2
  exit 1
fi

echo "Whitespace verification passed."
