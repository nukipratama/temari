#!/usr/bin/env bash
# WorktreeRemove hook — fully responsible for deleting the worktree
# directory, matching the docs' own literal example (`jq -r .worktree_path |
# xargs rm -rf`). Confirmed by testing: `git worktree remove` invoked FROM
# THIS HOOK does not actually remove anything (it works fine when run
# directly by the orchestrator, but silently no-ops from inside the hook —
# whether that's the isolation sandbox's "no git redirect into the main
# checkout" rule or a separate restriction on this specific git subcommand,
# the practical fix is the same: don't rely on git here, just rm -rf like
# the docs' own example does). A stale `git worktree list` entry left behind
# self-heals on the next `git worktree add`/`prune`.
#
# This hook's cwd is NOT reliably the main checkout (unlike WorktreeCreate),
# so paths are derived from the known `.claude/worktrees/<name>` layout in
# the JSON-provided absolute worktree_path, never from `cd`-then-relative.
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

rm -rf "$WORKTREE_PATH"
