#!/usr/bin/env bash
# PreToolUse(Bash). temari-only rules; everything else lives in ~/.claude/hooks/guard.sh.
set -uo pipefail

input=$(cat)
cmd=$(printf '%s' "$input" | jq -r '.tool_input.command // ""' 2>/dev/null)
[ -z "$cmd" ] && exit 0

# Heredoc bodies and quoted strings are text, not commands: a commit message or a
# grep pattern naming `phpstan analyse` must not trip the rule. Resumes at the
# terminator so anything chained after the heredoc is still scanned.
scan=$(printf '%s' "$cmd" | awk '
  s == 0 {
    print
    if (match($0, /<<-?[ \t]*['"'"'"]?[A-Za-z_][A-Za-z0-9_]*['"'"'"]?/)) {
      d = substr($0, RSTART, RLENGTH); sub(/^<<-?[ \t]*/, "", d); gsub(/['"'"'"]/, "", d); s = 1
    }
    next
  }
  s == 1 { t = $0; gsub(/^[ \t]+|[ \t]+$/, "", t); if (t == d) s = 0; next }
' | sed "s/'[^']*'//g; s/\"[^\"]*\"//g")
has() { printf '%s' "$scan" | grep -qiE "$1"; }

if has '\bphpstan\b' && has '\banaly[sz]e\b' && ! has '(--debug|--help|-h\b)'; then
  jq -cn '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"deny",
    permissionDecisionReason:"Run phpstan with --debug locally (single-process) - the parallel run races on the nette cache in Sail and crashes. Retry: ./vendor/bin/sail bin phpstan analyse --debug"}}'
  exit 0
fi

exit 0
