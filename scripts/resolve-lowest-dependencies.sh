#!/usr/bin/env bash
# Lowest-dependencies resolution sequence. It rewrites vendor/ with the lowest
# versions the constraints allow; run `composer update` afterwards to restore
# the latest ones. composer.lock is not tracked, so nothing else changes.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

composer validate --strict
composer update \
  --prefer-lowest \
  --prefer-stable \
  --no-interaction \
  --prefer-dist \
  --no-progress
composer check-platform-reqs
composer audit \
  --no-interaction \
  --abandoned=fail
