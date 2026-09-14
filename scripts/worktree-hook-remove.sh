#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
WORKTREE_PATH="$(jq -r '.worktree_path // empty')"
[ -n "$WORKTREE_PATH" ] || exit 0

exec "${SCRIPT_DIR}/worktree" remove "$WORKTREE_PATH"
