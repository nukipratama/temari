#!/bin/sh
# Prints the commit `vitest --changed` should diff the branch against.
#
# There is no safe fallback: a bare `vitest --changed` diffs the working tree
# against HEAD, so a clean checkout selects no files and the run exits 0 having
# tested nothing. An unresolvable base is therefore an error, not a default.
#
# POSIX sh, not bash: the dev image is Alpine and ships no bash.
set -eu

cd "$(dirname "$0")/.."

fail() {
  echo "vitest-changed-base: no base commit found (git merge-base HEAD $1 failed)." >&2

  if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "vitest-changed-base: git cannot read this checkout. In a worktree stack that means the" >&2
    echo "compose.override.yaml is missing or stale — re-run scripts/worktree-setup.sh <slot>." >&2
  fi

  exit 1
}

# Base precedence: GATE_BASE, then the PR base gh recorded in git config (no network call), then origin/main.
if [ -n "${GATE_BASE:-}" ]; then
  if base=$(git merge-base HEAD "$GATE_BASE" 2>/dev/null) && [ -n "$base" ]; then
    echo "    vitest --changed base: GATE_BASE=$GATE_BASE ($base)" >&2
    echo "$base"
    exit 0
  fi
  fail "GATE_BASE=$GATE_BASE"
fi

branch=$(git branch --show-current 2>/dev/null || true)
pr_base=
if [ -n "$branch" ]; then
  pr_base=$(git config --get "branch.$branch.gh-merge-base" 2>/dev/null || true)
fi

for ref in ${pr_base:+"origin/$pr_base"} origin/main; do
  if base=$(git merge-base HEAD "$ref" 2>/dev/null) && [ -n "$base" ]; then
    echo "    vitest --changed base: $ref ($base)" >&2
    echo "$base"
    exit 0
  fi
done

fail origin/main
