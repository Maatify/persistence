#!/usr/bin/env bash
# PHP syntax gate: lints every PHP file the package owns. A missing required
# path, a failing `find` or an empty inventory fails the gate.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

for required in src tests examples scripts; do
  if [[ ! -d "$required" ]]; then
    echo "Required directory is missing: ${required}" >&2
    exit 1
  fi
done

if [[ ! -f .php-cs-fixer.php ]]; then
  echo "Required file is missing: .php-cs-fixer.php" >&2
  exit 1
fi

inventory="$(mktemp)"
trap 'rm -f "$inventory"' EXIT

find src tests examples scripts -type f -name '*.php' -print0 > "$inventory"
printf '%s\0' .php-cs-fixer.php >> "$inventory"

count=0
while IFS= read -r -d '' file; do
  if ! output="$(php -l "$file" 2>&1)"; then
    printf '%s\n' "$output" >&2
    exit 1
  fi
  count=$((count + 1))
done < "$inventory"

if [[ "$count" -lt 2 ]]; then
  echo "PHP syntax inventory is unexpectedly empty." >&2
  exit 1
fi

echo "PHP syntax check passed: ${count} files."
