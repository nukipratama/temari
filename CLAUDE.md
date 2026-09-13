# CLAUDE.md

Read [AGENTS.md](AGENTS.md) completely before working in this repository. It is the single canonical project rule set shared with Codex.

## Claude Code orchestration

- Keep Opus for code writing, refactoring, and reasoning-heavy planning. Use `scout` (Haiku) to locate code and `reader` (Sonnet) for read-only investigation. Never spawn `general-purpose` without an explicit model; use Sonnet for mechanical or read-only work and Haiku for trivial sweeps.
- In `Workflow` stages, use Haiku or Sonnet with low effort for mechanical search/read stages; let verification, synthesis, and code-writing stages inherit Opus with high effort.
- For larger delegated implementation, `EnterWorktree` / `ExitWorktree` and the configured `WorktreeCreate` / `WorktreeRemove` hooks provide the Claude-specific lifecycle described in the `temari` skill. The manual Git plus setup fallback in `AGENTS.md` remains valid.
