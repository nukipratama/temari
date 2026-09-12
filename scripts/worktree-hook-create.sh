#!/usr/bin/env bash
# WorktreeCreate hook: picks the first free slot number via an atomic mkdir
# lock (race-safe across back-to-back worktree creation, uncapped), records
# it for worktree-hook-remove.sh, then hands off to worktree-setup.sh. See
# the "Parallel worktrees" section in the temari skill.
set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

GIT_COMMON_DIR="$(git rev-parse --path-format=absolute --git-common-dir)"
LOCKDIR="${GIT_COMMON_DIR}/temari-worktree-slots"
mkdir -p "$LOCKDIR"

SLOT=1
while ! mkdir "${LOCKDIR}/slot-${SLOT}" 2>/dev/null; do
  SLOT=$((SLOT + 1))
done

echo "$SLOT" > .claude-worktree-slot
exec ./scripts/worktree-setup.sh "$SLOT"
