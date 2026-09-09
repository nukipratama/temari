#!/bin/sh
#   ./vendor/bin/sail composer gate          # fast pre-push gate
#   ./vendor/bin/sail composer check:full    # everything CI runs
#
# POSIX sh, not bash: the dev image is Alpine and ships no bash.
set -eu

cd "$(dirname "$0")/.."

MODE=fast
case "${1:-}" in
  --full) MODE=full ;;
  "") ;;
  *) echo "Usage: $0 [--full]" >&2; exit 1 ;;
esac

GATE_STARTED_AT=$(date +%s)

step() {
  name=$1
  shift
  echo ">>> $name"
  started=$(date +%s)
  if ! "$@"; then
    failed_after=$(($(date +%s) - started))
    echo "GATE: FAIL at $name (${failed_after}s)"
    echo "GATE: FAIL at $name (${failed_after}s)" >&2
    exit 1
  fi
  echo "    $name ok ($(($(date +%s) - started))s)"
}

# vitest can exit non-zero when --changed selects nothing; that is a pass here.
vitest_changed() {
  base=$(sh scripts/vitest-changed-base.sh) || return 1
  status=0
  output=$(npx vitest run "--changed=$base" 2>&1) || status=$?
  echo "$output"
  if [ "$status" -ne 0 ] && echo "$output" | grep -q 'No test files found'; then
    return 0
  fi
  return "$status"
}

step "config:clear" php artisan config:clear --ansi
step "typescript:enums --check" php artisan typescript:enums --check
step "doc citations" php scripts/check-doc-citations.php
step "see references" php scripts/check-see-references.php
step "palette" npm run check:palette
step "pest structure" vendor/bin/pest --no-tia --group=structure
step "vitest structure" npx vitest run resources/js/test/structure.test.ts
step "typecheck" npm run typecheck
step "vitest changed" vitest_changed
step "pest" vendor/bin/pest --parallel --processes="${GATE_PEST_PROCESSES:-3}"

if [ "$MODE" = full ]; then
  step "pint" vendor/bin/pint --test
  step "format:check" npm run format:check
  step "lint" npm run lint
  step "phpstan" vendor/bin/phpstan analyse
  step "rector" vendor/bin/rector --dry-run
  step "pest --no-tia" vendor/bin/pest --no-tia --parallel
  step "vitest coverage" npm run test:coverage
  step "build" npm run build
  step "check:chunks" npm run check:chunks
fi

echo "GATE: PASS ($(($(date +%s) - GATE_STARTED_AT))s, mode=$MODE)"
