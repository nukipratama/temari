#!/usr/bin/env bash
# WorktreeRemove hook: tears down only this worktree's own app container —
# never compose.shared-services.yml, which every other worktree still uses —
# then frees its slot lock so a future worktree can reuse the number.
set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ ! -f .claude-worktree-slot ]; then
  echo "worktree-hook-remove: no .claude-worktree-slot marker — nothing to tear down" >&2
  exit 0
fi

SLOT="$(cat .claude-worktree-slot)"
docker compose down --remove-orphans || true

GIT_COMMON_DIR="$(git rev-parse --path-format=absolute --git-common-dir)"
rmdir "${GIT_COMMON_DIR}/temari-worktree-slots/slot-${SLOT}" 2>/dev/null || true
