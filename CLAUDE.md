# CLAUDE.md

@AGENTS.md

## Claude Code orchestration

- For larger delegated implementation, `EnterWorktree name=<slice>` invokes the `WorktreeCreate` hook and `ExitWorktree action=remove|keep` invokes `WorktreeRemove`. These hooks are configured in `.claude/settings.json` and are consumed only by Claude Code. The `scripts/worktree create`/`remove` commands in `AGENTS.md` remain valid for manual or other-agent use.
- A removal through the hooks leaves nothing behind: a measured `WorktreeCreate`/`WorktreeRemove` cycle on this tooling frees the directory, the `worktree-<name>` branch, the git worktree registration, the slot lock and the app container, with no `prunable` entry left. If a worktree is ever removed outside `scripts/worktree`, run `scripts/worktree prune` in the main checkout. It prunes the git registration and reclaims the slot's container, schemas and lock. Delete the stale branch yourself.
- During browser-review's Inspect phase, use a `Workflow` with disjoint `agent()` calls per viewport and inspection class; carry the canonical skill's probe-evidence contract into every call.
