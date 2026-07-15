#!/usr/bin/env bash
# PreToolUse(Bash) guard.
# DENY the never-do actions (push-to-main, force-push, secret dumps, prod volume
# deletion) and ASK before anything that needs explicit sign-off: merging a PR,
# touching prod (homelab), or discarding uncommitted working-tree changes.
# Non-matching commands defer to the
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
# Scope the check to the `git push` invocation's own args (up to the next
# segment separator), so a chained `git commit -F ...` or an echo mentioning
# "main" elsewhere on the line cannot trip it. Force is matched case-sensitively:
# `-f`/`--force` is force, `-F` (commit message file flag) is not.
push_seg=$(printf '%s' "$scan" | grep -oiE 'git +push[^|&;]*' | head -1)
if [ -n "$push_seg" ]; then
  if printf '%s' "$push_seg" | grep -qE '(--force|(^| )-f( |$))' \
     || printf '%s' "$push_seg" | grep -qiE '( main( |$)|origin +main|head:main|:main)'; then
    decide deny "No pushing to main and no force-push. Push a feature branch, then open a PR."
  fi
fi
if has '\bdocker +volume +rm\b'; then
  decide deny "Refusing to remove a Docker volume - prod data lives here."
fi
if { has 'printenv' || has '(^| )env( |$)'; } && has 'grep.*(pass|secret|token|key)'; then
  decide deny "Refusing to dump secrets from the environment."
fi

# ---------- ASK (explicit per-use confirmation) ----------
if has '\bgh +pr +merge\b'; then
  decide ask "Merging a PR needs your explicit OK. Confirm this merge?"
fi
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
