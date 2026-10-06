# CLAUDE.md

@AGENTS.md

## Claude Code orchestration

- For parallel work, `EnterWorktree name=<slice>` invokes the `WorktreeCreate` hook and `ExitWorktree action=remove|keep` invokes `WorktreeRemove`. Both hooks are configured in `.claude/settings.json`, are consumed only by Claude Code, and call the same `scripts/worktree create`/`remove` commands as the manual flow in `AGENTS.md`.
- A removal through the hooks leaves nothing behind: a measured `WorktreeCreate`/`WorktreeRemove` cycle on this tooling frees the directory, the `worktree-<name>` branch, the git worktree registration, the slot lock and the app container, with no `prunable` entry left. If a worktree is ever removed outside `scripts/worktree`, run `scripts/worktree prune` in the main checkout. It prunes the git registration and reclaims the slot's container, schemas and lock. Delete the stale branch yourself.
- Run long commands with Bash `timeout: 600000` instead of backgrounding them; run a screenshot-reader subagent with `run_in_background: false`.
- Bind each opened PR to the desktop app's CI monitor (`ccd_pr` `bind_pr`) and wait for its events instead of polling.
- During browser-review's Inspect phase, follow the skill: inspect sequentially yourself, or run at most 3 concurrent subagents, one per viewport pair, each carrying the skill's probe-evidence contract.
