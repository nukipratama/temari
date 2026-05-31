---
name: teman-lari
description: Project conventions and domain map for the teman-lari repo — Daybreak design tokens, Indonesian voice rules, the AI narrator/analysis pipeline, the 1:1 test convention with its aggregate suites, and the sail toolchain. Use when writing UI, AI narration, or tests in this codebase, or when unsure where a change wires in.
---

# teman-lari conventions

The detailed home for project conventions. Source-of-truth docs are generated from code and
kept honest by `tests/Unit/Architecture/DesignTokenDocsTest.php` (palette/type docs) — link to
them rather than re-copying, since copies drift.

## Voice & copy

- **Indonesian-first.** Only running-domain terms stay English (`pace`, `HR`, `km`, `TRIMP`, `splits`).
- **No em-dashes (`—`)** in UI copy *or* LLM prompt strings — they read as an AI/translation tell. Use commas, periods, colons, or `·`. (The `'—'` glyph as a *null placeholder* in data display is fine.)
- All user-facing copy (UI chrome, Temari narration, LLM prompts) follows one casual-Jakarta register: a code-switch test for English terms, a beginner-accessibility tier for jargon, a calque blacklist, and a `**bold**` emphasis rule.
- Full rules: [docs/voice-and-tone.md](../../../docs/voice-and-tone.md). Persona source of truth: [TemariPersona.php](../../../app/Services/AI/TemariPersona.php). Read it before writing or reviewing copy.

## Design system

Palette is **Daybreak** (pre-dawn Jakarta at 05:30). Tokens live in the `@theme` block of
[resources/css/app.css](../../../resources/css/app.css); full reference (colors, type scale,
fonts, gradients, spacing) in [docs/design-tokens.md](../../../docs/design-tokens.md). Use the
**semantic token families, never raw Tailwind colors** like `lime-500`:

- `sky` / `sky-deep` / `sky-2` (`#1f2747`) — structure, dark hero panels, the only "dark" surface.
- `horizon` / `horizon-deep` (`#e8a076` peach) — primary CTA, "earned"/PR state, Temari accent.
- `cream` / `cream-deep` (`#f6f1e8`) — paper / secondary surface and borders.
- `ink` / `ink-2` / `ink-3` — the 3-tier text-contrast scale (see below).
- `surface` / `surface-elev` / `surface-warm` / `surface-sunken` + `line` — app surfaces (dawn-shift drifts `surface`).
- `mood-{nyala,enteng,oleng,lemes,mumet,adem}` (each with a pastel `-bg` variant) — calendar cells + mood badges.
- `rarity-{common,uncommon,rare,epic,legendary}` — card rarity.
- semantic hues `leaf` / `leaf-deep`, `ember` / `ember-deep`, `citrus` / `citrus-deep`, `stone`.
- `strava-orange` / `strava-orange-hover` — reserved, never themed (see below).

`citrus` mustard (`#d9b23a`) is reserved for PR / legendaris celebrations only. App is
**light-mode only** (no `*-dark` tokens; `.dark` is never applied).

### Strava brand mark — hands off

The "Connect with Strava" button (and any Strava brand mark) is never restyled. Strava brand
orange `#FC4C02` / hover `#E34402` are reserved via `--color-strava-orange` tokens. The warm
`horizon` peach (`#e8a076`) and `ember` share a hue family with Strava orange, so within any
card that **displays the Strava brand mark** the warm accent is *not* used: switch the local
context to neutral (`surface-sunken` + `ink`) so the brand mark gets breathing room. Strava can
revoke API access for brand-guideline violations.

### CTA contrast rule (WCAG)

`horizon` (`#e8a076`) is a light peach, so it pairs with **dark** text, never white. Follow the
[`PillButton`](../../../resources/js/components/ui/PillButton.tsx) presets:
- `horizon` bg → `text-sky` (dark navy on peach passes comfortably); hover darkens to `horizon-deep`.
- `sky` / `sky-deep` bg (dark navy) → `text-cream` / white text (passes ~12:1); hover darkens to `sky-deep`.
- `leaf-deep` (`#4f6c54`) bg → white text (passes AA ~4.9:1); used for dense "retry"/action chips. No darker leaf token exists, so darken on hover with `hover:opacity-90`, not a hue jump.
- Never put white text on `horizon`/`citrus`/`cream` (all too light).

### Gradient primitives

Gradient **text** is applied via
[`<GradientText preset="horizon|cream-sun" fontSize=… />`](../../../resources/js/components/ui/GradientText.tsx),
which clips a `linear-gradient` to the text via inline `background-clip`. Rule: **gradient text
on numbers only**, only at large display sizes, and only one per visible viewport. Scarcity makes
it feel premium, not Las-Vegas. Backdrop atmospherics use
[`<MeshBackdrop variant="dawn|night|ember" />`](../../../resources/js/components/MeshBackdrop.tsx)
(three blurred radial blobs) inside `relative overflow-hidden` parents; used mainly on the login
page, in-app pages stay clean.

### Dawn-shift theme

[`useDawnShift`](../../../resources/js/hooks/useDawnShift.ts) is mounted in
[AppShell](../../../resources/js/layouts/AppShell.tsx); it writes
`data-time-of-day="dawn|morning|day|dusk|night"` on `<body>` so CSS surface tints respond to the
user's local time. Light mode only — never auto-flips to dark mode.

### Text contrast tiers

3-stop semantic system — use the tier that matches the text role, not "pick whichever color looks right":

- `text-ink` (`#1a1812`) — **primary text**: body paragraphs, headings, button labels, KPI values. Default for any prose the user reads.
- `text-ink-2` (`#3d362a`) — **supporting body**: page subtitles, briefing suggestion lines, descriptive paragraphs adjacent to a primary statement.
- `text-ink-3` (`#7a6f5c`) — **labels-above-values, timestamps, footnotes, table column headers, secondary metadata**. Smallest contrast tier, never use for body prose.

Sweep `grep text-ink-3` before merging — if it's wrapping a `<p>` of running prose, it's probably wrong.

### Typography & fonts

Three families (all loaded via Google Fonts in
[app.blade.php](../../../resources/views/app.blade.php)): **Instrument Serif** italic is
`font-display` (headlines + Temari voice/quotes); **Inter** is `font-sans`, the default family
for body/UI/numbers/buttons; **JetBrains Mono** is `font-mono`, reserved for *small uppercase
metadata labels only* (section labels, chips, stat-tile / kartu captions, timestamps). Because
`font-sans` (Inter) is Tailwind's default, every small uppercase label must carry an **explicit
`font-mono`** (or the `.text-label-micro` / `.text-label-small` utilities) or it falls back to
Inter. Keep `tabular-nums` on numeric / stat displays.
The scale is fluid `clamp()` tokens in `app.css` (`text-display-*`, `text-headline-*`,
`text-quote-*`), each bundling its own line-height + letter-spacing, so one utility lands the full spec.

| Role | Class |
|---|---|
| In-app hero title | `font-display italic text-display-2xl text-ink` |
| Page title (`<h1>`) | `font-display text-display-lg text-ink` (compact/devtools header: `text-headline-xs`) |
| Section heading (`<h2>`) | `font-display text-headline-sm text-ink` |
| Temari voice / quote | `font-display italic text-quote-lg text-ink-2` |
| Sub-label (KPI/table cap) | `font-mono text-xs font-semibold uppercase tracking-wider text-ink-3` |
| Body paragraph | `font-sans text-sm leading-relaxed text-ink` |
| Caption / supporting | `text-sm text-ink-2 leading-relaxed` |
| Meta / timestamp | `text-xs text-ink-3` |
| KPI / big stat value | display tier (`text-display-xs`+) `tabular-nums text-ink`; avoid one-off `text-[NNpx]` |

### Section spacing rhythm

- Major section → next major: `mt-10`
- Subsection → next: `mt-6`
- `<h2>` → content: `mt-3`
- Page header → first section: `mt-8`
- Hero card padding: `p-6`; data card padding: `p-4`; chip/pill: `px-3 py-1`

## AI narration pipeline

Every narrated block flows: **Narrator → Analyze\*Job → Analysis row → AnalysisType → AnalysisController → UI (AnalysisStatus)**.
Adding a new narrated block touches ~6 places — use the **`/add-narrator`** command so none are
missed (a missing wire fails the structure tests). The failure model, idempotency guard, and
unconfigured-env fallback are documented in the always-on guideline ("LLM Integration" in CLAUDE.md).

## Testing

- **1:1 class↔test.** Every concrete class has a `{Name}Test.php`, or is exempt in [tests/Unit/Architecture/EveryClassHasATestTest.php](../../../tests/Unit/Architecture/EveryClassHasATestTest.php). Frontend: co-located `{name}.test.tsx`, guarded by [resources/js/test/structure.test.ts](../../../resources/js/test/structure.test.ts).
- **Aggregate suites** cover whole families: narrators → `NarratorsCoverageTest`, AI jobs → `JobsCoverageTest`. A new narrator/job must be registered there.
- Structure tests live in the `structure` group and run **before** coverage in CI (fast fail). Gate: 95% line+function coverage.

## Toolchain (everything in Docker via Sail)

**Fast-feedback ladder** (cheap to expensive, stop at the first failure, don't jump to the full gate):
```bash
./vendor/bin/sail pest --group=structure   # instant: 1:1 + aggregate structural gates. Run first.
./vendor/bin/sail bin pest --filter=Name    # targeted: one test/feature while iterating
./vendor/bin/sail bin pest --parallel       # full PHP suite (local parallel — see docker/mysql-test-init.sh)
./vendor/bin/sail composer check            # full gate: pint + phpstan + rector + pest --parallel + tsc + vitest. Pre-push only.
./vendor/bin/sail bin pint                  # format (also runs on pre-commit with phpstan + rector)
```
Code quality (pint/phpstan/rector/tsc) runs on **pre-commit**; coverage runs in **CI**.

**Dev commands:**
- After changing a PHP enum exposed to TS: `./vendor/bin/sail artisan typescript:enums` (`--check` mirrors CI).
- Local UI/demo data (deterministic, no LLM tokens, no Strava HTTP): `./vendor/bin/sail artisan demo:seed [--fresh]`.

## Boost MCP tools

Wired via [.mcp.json](../../../.mcp.json) (runs `boost:mcp` in the Sail container, so the container must be up). Prefer these over guessing; when a bug is reported, start here before hypothesizing:
- **`search-docs`** — version-correct docs for this exact stack (Laravel 13 / Inertia v3 / React 19 / Tailwind v4 / Pest 4). Use it before reaching for memory on framework APIs; they drift.
- **`database-query` / `database-schema` / `database-connections`** — inspect real data and schema instead of inferring from migrations.
- **`read-log-entries` / `last-error`** — read actual app errors.
- **`browser-logs`** — live React/Inertia console errors (this is how you confirm UI changes, since [tests/Feature/Smoke/PagesRenderTest.php](../../../tests/Feature/Smoke/PagesRenderTest.php) only asserts server-side render).
- **`application-info` / `get-absolute-url`** — env/package versions and route URLs.
