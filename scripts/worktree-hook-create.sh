#!/usr/bin/env bash
# WorktreeCreate hook — once configured, this REPLACES Claude Code's default
# git-worktree-creation entirely (Claude Code no longer runs `git worktree
# add` itself). Creates a real git worktree (so ordinary git tooling still
# works inside it), picks a free shared-services slot, and runs the usual
# environment setup. Per the documented contract: all status output must go
# to stderr, and ONLY the final absolute worktree path goes to stdout as the
# last non-empty line — that's what Claude Code reads back.
set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."   # this hook always runs from the main checkout

NAME="$(jq -r '.name // empty')"
[ -n "$NAME" ] || NAME="wt-$$"
DIR=".claude/worktrees/${NAME}"
ABS_DIR="$(pwd)/${DIR}"
BRANCH="worktree-${NAME}"

if [ ! -e "$DIR" ]; then
  git worktree add "$DIR" -b "$BRANCH" HEAD >&2
fi

GIT_COMMON_DIR="$(git rev-parse --path-format=absolute --git-common-dir)"
LOCKDIR="${GIT_COMMON_DIR}/temari-worktree-slots"
mkdir -p "$LOCKDIR"

SLOT=1
while ! mkdir "${LOCKDIR}/slot-${SLOT}" 2>/dev/null; do
  SLOT=$((SLOT + 1))
done

(
  cd "$DIR"
  echo "$SLOT" > .claude-worktree-slot
  ./scripts/worktree-setup.sh "$SLOT"
) >&2

echo "$ABS_DIR"
