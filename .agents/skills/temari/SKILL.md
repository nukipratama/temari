---
name: temari
description: Project conventions and domain map for the temari repo — design tokens, voice rules, the AI narrator/analysis pipeline, the 1:1 test convention with its aggregate suites, and the Sail toolchain. Use when writing UI, AI narration, or tests in this codebase, or when unsure where a change wires in.
---

# temari conventions

This is the canonical full skill shared by agents. Source-of-truth docs are generated from code and
kept honest by `tests/Unit/Architecture/DesignTokenDocsTest.php` (palette/type docs) — link to
them rather than re-copying, since copies drift.

## Tracking

Issue tracking, decision labels, the kanban flow and the PR handoff standard are in
[AGENTS.md](../../../AGENTS.md). Other labels: `wave:tooling` / `wave:bugs` / `wave:engine-1` /
`wave:engine-2` / `wave:refine` for the programme wave, `design-round` for design rounds, and
`area:*` for the subsystem.

## Codebase map

System overview (principles, subsystems, data lifecycle): [docs/DESIGN.md](../../../docs/DESIGN.md).

Backend logic is split by domain under `app/Services/`:
- **AI/** — narrators + the Analysis pipeline (see [narration.md](references/narration.md)).
- **Run/** — ingest (Strava activity → `ActivityDetail` + streams), metrics (`TrainingLoad`, `PersonalRecords`, VDOT/threshold estimators, `WeeklyAggregator`), and story (`Vibe`, `Temari`, `BriefingComposer`, `RunCardFactory`).
- **Gamification/** — `SeasonGoalResolver`, `SeasonGamificationContext`, `SeasonPayloadBuilder`, `StreakSettlementService` (plus `DetectActivityMilestonesAction` under `app/Actions/Gamification/`).
- **Strava/** — OAuth client, activity fetch, webhook + sync orchestration.
- **Geo/** — polyline encode/decode + Nominatim reverse-geocode (`app/Jobs/Geo/` resolves location names).
- **Weather/** — Open-Meteo snapshot attached per activity.
- **Telegram/** — client, link tokens, notification-eligible types, reply handling.
- **Notifications/** — channel routing + delivery-claim idempotency.
- **Inertia/** — per-page shared prop builders (`SharedProps`, `AiProps`, `GamificationProps`, `NotificationProps`, `StravaProps`).

Two DB connections: default `mysql` plus a second **`analytics`** schema for metering (e.g. `ai_token_usages`); its migrations live in `database/migrations/analytics/`. Pages live under `resources/js/pages/`, one per prototype screen: `Home` (the Today dashboard — the render name is `Home`, not `Today`), `Plan`, `Race`, `Trends`, `History` with `Activities/{Feed,Calendar}`, `Runs/Show`, `Inbox`, `Profile`, `Settings/Index`, plus `Auth/Login`, `Onboarding/Index`, `Legal/Document` and the operator screens `Narration/Overview` / `Devtools` / `Devtools/Design`. There is no `Collection/` tree — the cards, records and accessories pages were cut by the parity port.

## Voice & copy

- **Prefer commas, periods, colons or `·` over em-dashes (`—`)** in UI copy and LLM prompt strings — a reflexive em-dash reads as an AI tell. This is a preference, not a ban: a deliberate one is fine, and the `'—'` glyph as a *null placeholder* in data display always was. Nothing gates it (the hard test was cut in `C1`); it is a judgement call at review time. `TemariPersona`'s prompt still instructs the model itself to avoid them, which is separate and unchanged.
- Temari is a training partner who keeps score, not a soft cheerleader: warm, but competitive about the user's own numbers (never against other runners), willing to name a coast once and plainly, and stingy with praise so it means something when given. Her narrated voice leans lowercase (a soft tendency, not a rule) and dry-funny; **UI chrome is lowercase too** since 2026-09-01 (decision P37 of the prototype-parity program, which replaced the previous Title Case rule), with proper nouns, domain acronyms and CSS-uppercased mono labels unaffected. Shared across both: plain running-domain vocabulary (`pace`, `HR`, `km`, `TRIMP`, `splits`), a jargon-accessibility tier for technical terms, a `**bold**` emphasis rule, and a tight emoji rule (zero by default, one max, only for a genuine PR/first-ever, glyphs limited to 🔥/✨/🛌).
- Full rules: [docs/voice-and-tone.md](../../../docs/voice-and-tone.md). Persona source of truth: [TemariPersona.php](../../../app/Services/AI/TemariPersona.php). Read it before writing or reviewing copy.

## Reference index

Read only the file the task needs; each holds its section verbatim.

- [Design system](references/design-system.md): before any UI or styling work. Tokens, grounds, Strava brand mark, CTA contrast rule, gradient primitives, text contrast tiers, typography and fonts, section spacing.
- [AI narration pipeline](references/narration.md): before touching a narrator, prompt, Analyze\*Job or `AnalysisType`, including "Adding a new narrated block — all 6 wires".
- [Testing](references/testing.md): before writing or moving tests. The 1:1 class↔test rule, aggregate suites, DB isolation, test speed.
- [Sail toolchain](references/toolchain.md) ("Toolchain (everything in Docker via Sail)"): before running the gate, tests, builds or `demo:seed`.
- [Parallel worktrees](references/parallel-worktrees.md), including "Shared services": before starting a second Sail stack or working in a worktree.
- [Stacked PRs](references/stacked-prs.md): before starting, building or merging a `gh stack`.

## Inspecting real state

Data, schema, log and console commands are under "Debugging" in [AGENTS.md](../../../AGENTS.md).
For framework APIs, read the installed source under `vendor/` rather than recalling; this stack
(Laravel 13 / Inertia v3 / React 19 / Tailwind v4 / Pest 5) drifts fast.
