# CLAUDE.md

Read [AGENTS.md](AGENTS.md) completely before working in this repository. It is the canonical shared project rule set; this file contains only Claude Code orchestration.

## Claude Code orchestration

- For larger delegated implementation, `EnterWorktree name=<slice>` invokes the `WorktreeCreate` hook and `ExitWorktree action=remove|keep` invokes `WorktreeRemove`. These hooks are configured in `.claude/settings.json` and are consumed only by Claude Code. The manual Git plus setup flow in `AGENTS.md` remains valid.
- A Claude Code removal can leave a `prunable` worktree entry and its `worktree-<name>` branch after the hook has removed the directory, app container, and slot lock. Clean that residue from the main checkout with `git worktree prune` and then delete the stale branch.
- During browser-review's Inspect phase, use a `Workflow` with disjoint `agent()` calls per viewport and inspection class; carry the canonical skill's probe-evidence contract into every call.
