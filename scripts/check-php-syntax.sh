#!/usr/bin/env bash
# PHP syntax gate: lints every PHP file the package owns.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

count=0
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null || { php -l "$file" >&2 || true; exit 1; }
  count=$((count + 1))
done < <(
  find src tests examples scripts -type f -name '*.php' -print0 2>/dev/null
  printf '%s\0' .php-cs-fixer.php
)

echo "PHP syntax check passed: ${count} files."
