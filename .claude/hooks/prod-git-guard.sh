#!/usr/bin/env bash
# PreToolUse(Bash) guard.
# DENY the never-do actions (self-merge, push-to-main, force-push, secret dumps,
# prod volume deletion) and ASK before anything that touches prod (homelab) or
# discards uncommitted working-tree changes. Non-matching commands defer to the
# normal permission flow (exit 0, no output). See the CC-feature plan.
set -uo pipefail

input=$(cat)
cmd=$(printf '%s' "$input" | jq -r '.tool_input.command // ""' 2>/dev/null)
[ -z "$cmd" ] && exit 0

# Match against the command with quoted substrings removed, so a dangerous
# phrase inside a commit message (-m "...") or an echo/doc string doesn't trip
# the guard - real dangerous invocations are unquoted command tokens.
scan=$(printf '%s' "$cmd" | sed "s/'[^']*'//g; s/\"[^\"]*\"//g")
has() { printf '%s' "$scan" | grep -qiE "$1"; }

decide() { # $1=deny|ask  $2=reason
  jq -cn --arg d "$1" --arg r "$2" \
    '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:$d,permissionDecisionReason:$r}}'
  exit 0
}

# ---------- hard DENY ----------
if has '\bgh +pr +merge\b'; then
  decide deny "Never self-merge. Open the PR and let a human review + merge via the GitHub UI."
fi
if has '\bgit +push\b' && { has '(--force|(^| )-f( |$))' || has '( main( |$)|origin +main|head:main|:main)'; }; then
  decide deny "No pushing to main and no force-push. Push a feature branch, then open a PR."
fi
if has '\bdocker +volume +rm\b'; then
  decide deny "Refusing to remove a Docker volume - prod data lives here."
fi
if { has 'printenv' || has '(^| )env( |$)'; } && has 'grep.*(pass|secret|token|key)'; then
  decide deny "Refusing to dump secrets from the environment."
fi

# ---------- ASK (explicit per-use confirmation) ----------
if has '\bssh +homelab\b'; then
  decide ask "Prod (homelab) access is never pre-authorized. Confirm this exact command?"
fi
if has '\bdocker +exec\b.*-prod-'; then
  decide ask "This touches a prod container. Confirm?"
fi
if has '\bgit +reset +--hard\b' || has '\bgit +clean +-[a-z]*f' || has '\bgit +restore\b' || has '\bgit +checkout +(--|\.( |$))'; then
  decide ask "This discards working-tree changes you may not have authored. Confirm?"
fi

exit 0
