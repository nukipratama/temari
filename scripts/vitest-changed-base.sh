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

if base=$(git merge-base HEAD origin/main 2>/dev/null) && [ -n "$base" ]; then
  echo "    vitest --changed base: origin/main ($base)" >&2
  echo "$base"
  exit 0
fi

echo "vitest-changed-base: no base commit found (git merge-base HEAD origin/main failed)." >&2

if ! git rev-parse --git-dir >/dev/null 2>&1; then
  echo "vitest-changed-base: git cannot read this checkout. In a worktree stack that means the" >&2
  echo "compose.override.yaml is missing or stale — re-run scripts/worktree-setup.sh <slot>." >&2
fi

exit 1
