#!/usr/bin/env bash
# PreToolUse(Bash) — temari-specific guard.
#
# Replaces prod-git-guard.sh + phpstan-debug.sh. Everything that was NOT actually
# temari-specific moved to ~/.claude/hooks/guard.sh:
#
#   ssh homelab, docker exec *-prod-*, docker volume rm, gh pr merge, env-secret
#   dumps, push-to-protected-branch, working-tree discards
#
# Those were only ever wired into temari's settings.json, so they did not apply
# from any other directory — `ssh homelab` from mamikos-web or from $HOME was
# unguarded. The intent was always global; only the wiring was project-scoped.
#
# What remains here is genuinely repo-specific: the phpstan/Sail interaction.
# Behavior verified against ~/.claude/hooks/tests/golden.tsv.
set -uo pipefail

input=$(cat)
cmd=$(printf '%s' "$input" | jq -r '.tool_input.command // ""' 2>/dev/null)
[ -z "$cmd" ] && exit 0

# Match with quoted substrings removed, so merely MENTIONING phpstan (a grep, an
# echo, a commit message) is not treated as running it.
scan=$(printf '%s' "$cmd" | sed "s/'[^']*'//g; s/\"[^\"]*\"//g")
has() { printf '%s' "$scan" | grep -qiE "$1"; }

# A direct `phpstan analyse` without --debug hits the nette parallel-cache race in
# the Sail container and crashes.
if has '\bphpstan\b' && has '\banaly[sz]e\b' && ! has '(--debug|--help|-h\b)'; then
  jq -cn '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"deny",
    permissionDecisionReason:"Run phpstan with --debug locally (single-process) - the parallel run races on the nette cache in Sail and crashes. Retry: ./vendor/bin/sail bin phpstan analyse --debug"}}'
  exit 0
fi

exit 0
