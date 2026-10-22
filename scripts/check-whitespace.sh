#!/usr/bin/env bash
# Whitespace gate: verifies the whole committed tree (HEAD against the empty
# tree), then any local staged, unstaged and untracked changes. A bare
# `git diff --check` on a clean checkout compares nothing and always passes.
# Verbatim evidence that must keep its bytes is exempted per file with a
# `-whitespace` attribute in the .gitattributes next to it.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

status=0
empty_tree="$(git hash-object -t tree /dev/null)"

echo "Checking committed tree (HEAD against the empty tree)"
git diff --check "$empty_tree" HEAD || status=1

echo "Checking staged changes"
git diff --cached --check || status=1

echo "Checking unstaged changes"
git diff --check || status=1

echo "Checking untracked files"
while IFS= read -r -d '' file; do
  # --no-index exits 1 for any difference; the --check report itself is the signal.
  report="$(git diff --check --no-index /dev/null "$file" || true)"
  if [[ -n "$report" ]]; then
    printf '%s\n' "$report"
    status=1
  fi
done < <(git ls-files --others --exclude-standard -z)

if [[ "$status" -ne 0 ]]; then
  echo "Whitespace verification failed." >&2
  exit 1
fi

echo "Whitespace verification passed."
