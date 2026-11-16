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
GATE_LOG=storage/logs/gate.log
STEP_DIR=storage/logs/gate-steps
: > "$GATE_LOG"
rm -rf "$STEP_DIR"
mkdir -p "$STEP_DIR"
STEP_IDS=""
STEP_COUNT=0
GATE_FAILED=""
FAILED_IDS=""

trap 'kill 0' INT TERM

# Each step runs in the background into its own log; report waits for all of
# them and prints one line per step, and finish prints only the failing steps'
# tails, so the gate's own output is short enough to read unpiped.
step() {
  STEP_COUNT=$((STEP_COUNT + 1))
  id=$(printf '%02d' "$STEP_COUNT")
  STEP_IDS="$STEP_IDS $id"
  printf '%s\n' "$1" > "$STEP_DIR/$id.name"
  shift
  (
    started=$(date +%s)
    step_status=0
    "$@" > "$STEP_DIR/$id.log" 2>&1 || step_status=$?
    echo "$step_status $(($(date +%s) - started))" > "$STEP_DIR/$id.result"
  ) &
}

report() {
  wait
  for id in $STEP_IDS; do
    name=$(cat "$STEP_DIR/$id.name")
    read -r step_status seconds < "$STEP_DIR/$id.result"
    { echo ">>> $name"; cat "$STEP_DIR/$id.log"; } >> "$GATE_LOG"
    if [ "$step_status" -eq 0 ]; then
      echo "    $name ok (${seconds}s)"
    else
      echo "    $name FAIL (${seconds}s)"
      GATE_FAILED="${GATE_FAILED:+$GATE_FAILED, }$name"
      FAILED_IDS="$FAILED_IDS $id"
    fi
  done
  STEP_IDS=""
}

finish() {
  elapsed=$(($(date +%s) - GATE_STARTED_AT))
  if [ -z "$GATE_FAILED" ]; then
    echo "GATE: PASS (${elapsed}s, mode=$MODE)"
    exit 0
  fi
  for id in $FAILED_IDS; do
    echo ">>> $(cat "$STEP_DIR/$id.name")"
    tail -n 40 "$STEP_DIR/$id.log"
  done
  echo "    full output: $GATE_LOG"
  echo "GATE: FAIL at $GATE_FAILED (${elapsed}s)"
  echo "GATE: FAIL at $GATE_FAILED (${elapsed}s)" >&2
  exit 1
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

# rector.php's own paths are app/ and tests/, so a changed file outside them is
# not ours to check; deleted files are excluded by --diff-filter=ACMR.
changed_paths() {
  git diff --name-only --diff-filter=ACMR "$1...HEAD"
  git diff --name-only --diff-filter=ACMR --cached
  git diff --name-only --diff-filter=ACMR
  git ls-files --others --exclude-standard
}

rector_changed() {
  base=$(sh scripts/vitest-changed-base.sh) || return 1
  files=$(changed_paths "$base" | grep -E '^(app|tests)/.+\.php$' | sort -u)
  if [ -z "$files" ]; then
    echo "    no changed PHP files under app/ or tests/"
    return 0
  fi
  echo "$files" | xargs vendor/bin/rector process --dry-run --no-progress-bar
}

# The full suite is CI's; locally Pest runs only the tests paired with changed
# files, so the gate stays seconds long however many worktrees run it.
pest_changed() {
  base=$(sh scripts/vitest-changed-base.sh) || return 1
  tests=$(changed_paths "$base" | sh scripts/changed-tests.sh)
  if [ -z "$tests" ]; then
    echo "    no changed classes or tests; CI runs the full suite"
    return 0
  fi
  echo "$tests" | sed 's/^/    /'
  echo "$tests" | xargs vendor/bin/pest
}

migration_safety() {
  base=$(sh scripts/vitest-changed-base.sh) || return 1
  php scripts/check-migration-safety.php --base "$base"
}

build_and_check_chunks() {
  npm run build && npm run check:chunks
}

step "config:clear" php artisan config:clear --ansi
report

step "typescript:enums --check" php artisan typescript:enums --check
step "doc citations" php scripts/check-doc-citations.php
step "see references" php scripts/check-see-references.php
step "palette" npm run check:palette
step "pest structure" vendor/bin/pest --group=structure
step "vitest structure" npx vitest run resources/js/test/structure.test.ts
step "typecheck" npm run typecheck -- --incremental --tsBuildInfoFile node_modules/.cache/tsbuildinfo

# Full mode's whole-tree Rector, Vitest and Pest runs replace the changed-file
# steps instead of racing them on the same caches and test databases.
if [ "$MODE" = fast ]; then
  step "rector changed" rector_changed
  step "vitest changed" vitest_changed
  step "pest changed" pest_changed
else
  step "pint" vendor/bin/pint --test
  step "format:check" npm run format:check
  step "lint" npm run lint
  step "phpstan" vendor/bin/phpstan analyse
  step "rector" vendor/bin/rector --dry-run
  step "migration safety" migration_safety
  step "build + check:chunks" build_and_check_chunks
fi
report

# The whole Pest and Vitest suites each saturate the CPU and time out when run
# together, so they take turns.
if [ "$MODE" = full ]; then
  step "pest" vendor/bin/pest --parallel
  report
  step "vitest coverage" npm run test:coverage
  report
fi

finish
