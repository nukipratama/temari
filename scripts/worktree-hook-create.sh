#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
NAME="$(jq -r '.name // empty')"
[ -n "$NAME" ] || NAME="wt-$$"

exec "${SCRIPT_DIR}/worktree" create "$NAME"
