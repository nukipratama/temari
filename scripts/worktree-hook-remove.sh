#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
WORKTREE_PATH="$(jq -r '.worktree_path // empty')"
[ -n "$WORKTREE_PATH" ] || exit 0

# ExitWorktree already refuses to drop unsaved work unless discard_changes is set.
exec "${SCRIPT_DIR}/worktree" remove --force "$WORKTREE_PATH"
