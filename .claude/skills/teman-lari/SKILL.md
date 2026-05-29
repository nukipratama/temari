---
name: teman-lari
description: Project conventions and domain map for the teman-lari repo — Daybreak design tokens, Indonesian voice rules, the AI narrator/analysis pipeline, the 1:1 test convention with its aggregate suites, and the sail toolchain. Use when writing UI, AI narration, or tests in this codebase, or when unsure where a change wires in.
---

# teman-lari conventions

Navigational hub. It points at the source-of-truth files rather than copying them
(copies drift — guarded by `tests/Unit/Architecture/DesignTokenDocsTest.php`).

## Voice & copy
- **Indonesian-first.** Only running-domain terms stay English (`pace`, `HR`, `km`, `TRIMP`, `splits`).
- **No em-dashes (`—`)** in UI copy *or* LLM prompt strings — they read as an AI/translation tell. Use commas, periods, colons, or `·`. (The `'—'` glyph as a *null placeholder* in data display is fine.)

## Design system
- Source of truth: [docs/design-tokens.md](../../../docs/design-tokens.md), generated from the `@theme` block of [resources/css/app.css](../../../resources/css/app.css).
- Use semantic tokens (`sky`, `horizon`, `cream`, `ink`/`ink-2`/`ink-3`, `surface*`, `mood-*`, `rarity-*`, `leaf`/`ember`/`citrus`/`stone`, `text-display-*`/`headline-*`/`quote-*`/`stat`). Never raw Tailwind colors.
- Light-mode only. Full rules in [CLAUDE.md](../../../CLAUDE.md).

## AI narration pipeline
Every narrated block flows: **Narrator → Analyze\*Job → Analysis row → AnalysisType → AnalysisController → UI (AnalysisStatus)**.
Adding a new narrated block touches ~6 places — use the **`/add-narrator`** command so none are missed (a missing wire fails the structure tests). Failure model + idempotency: [CLAUDE.md](../../../CLAUDE.md) "LLM Integration".

## Testing
- **1:1 class↔test.** Every concrete class has a `{Name}Test.php`, or is exempt in [tests/Unit/Architecture/EveryClassHasATestTest.php](../../../tests/Unit/Architecture/EveryClassHasATestTest.php). Frontend: co-located `{name}.test.tsx`, guarded by [resources/js/test/structure.test.ts](../../../resources/js/test/structure.test.ts).
- **Aggregate suites** cover whole families: narrators → `NarratorsCoverageTest`, AI jobs → `JobsCoverageTest`. A new narrator/job must be registered there.
- Structure tests live in the `structure` group and run **before** coverage in CI (fast fail). Gate: 95% line+function coverage.

## Toolchain (everything in Docker via Sail)
```bash
./vendor/bin/sail composer check          # full gate: pint + phpstan + rector + pest --parallel + tsc + vitest
./vendor/bin/sail bin pest --parallel     # fast PHP tests (local parallel works — see docker/mysql-test-init.sh)
./vendor/bin/sail pest --group=structure  # just the fast structural gates
./vendor/bin/sail bin pint                # format (also runs on pre-commit with phpstan + rector)
```
Code quality (pint/phpstan/rector/tsc) runs on **pre-commit**; coverage runs in **CI**.
