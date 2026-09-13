---
name: browser-review
description: Drive a real browser to screenshot every user-facing page across a mobile/tablet/laptop/desktop viewport matrix, capture console errors, and audit for horizontal overflow — an end-to-end visual UI review. Use when asked to "browser review", "screenshot every page", "mobile UI review", "check the UI on mobile/tablet", "full browser check", or "review the app end to end" in this repo.
---

# browser-review for Codex

Read [the canonical browser-review skill](../../../.claude/skills/browser-review/SKILL.md) completely and follow it. Keep every script path under `.claude/skills/browser-review/scripts/`; those existing tracked scripts are shared by both tools.

The canonical skill's `Workflow` / `agent()` syntax and Haiku/Sonnet mappings are Claude-specific. In Codex, preserve the same split with Luna for audit-flagged confirm-only reads and Sol at medium effort for sampled visual judgment, using Codex delegation when available.
