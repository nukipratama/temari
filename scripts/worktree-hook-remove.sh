#!/usr/bin/env bash
# WorktreeRemove hook — fully responsible for removing the worktree.
#
# An earlier version used `git -C "$MAIN_DIR" worktree remove ...`, which
# silently failed to remove anything when run from inside this hook (though
# the identical command worked fine run directly by the orchestrator).
# Matches Claude Code's own documented isolation rule: a `git -C` redirect
# into the main checkout is blocked. The fix, confirmed against a community
# reference implementation (tfriedel/claude-worktree-hooks) doing the same
# thing: call `git worktree remove` with NO `-C` flag at all — git resolves
# the right repo from the target path's own metadata, so nothing "redirects
# into" the main checkout.
set -Eeuo pipefail

WORKTREE_PATH="$(jq -r '.worktree_path // empty')"
[ -n "$WORKTREE_PATH" ] || exit 0

if [ -f "${WORKTREE_PATH}/.claude-worktree-slot" ]; then
  SLOT="$(cat "${WORKTREE_PATH}/.claude-worktree-slot")"
  # Never touches compose.shared-services.yml — only this worktree's own app.
  (cd "$WORKTREE_PATH" && docker compose down --remove-orphans) >&2 || true
  # -C targets the worktree itself (a read-only query, not a write into the
  # main checkout), which is fine even though the result points into it.
  GIT_COMMON_DIR="$(git -C "$WORKTREE_PATH" rev-parse --path-format=absolute --git-common-dir 2>/dev/null || true)"
  [ -n "$GIT_COMMON_DIR" ] && rmdir "${GIT_COMMON_DIR}/temari-worktree-slots/slot-${SLOT}" 2>/dev/null || true
fi

BRANCH="$(git -C "$WORKTREE_PATH" rev-parse --abbrev-ref HEAD 2>/dev/null || true)"
git worktree remove "$WORKTREE_PATH" --force >&2 || true
if [ -n "$BRANCH" ] && [[ "$BRANCH" == worktree-* ]]; then
  git branch -D "$BRANCH" >&2 || true
fi
