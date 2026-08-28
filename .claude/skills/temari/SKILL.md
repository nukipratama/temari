---
name: temari
description: Project conventions and domain map for the temari repo — design tokens, voice rules, the AI narrator/analysis pipeline, the 1:1 test convention with its aggregate suites, and the sail toolchain. Use when writing UI, AI narration, or tests in this codebase, or when unsure where a change wires in.
---

# temari conventions

The detailed home for project conventions. Source-of-truth docs are generated from code and
kept honest by `tests/Unit/Architecture/DesignTokenDocsTest.php` (palette/type docs) — link to
them rather than re-copying, since copies drift.

## Codebase map

Backend logic is split by domain under `app/Services/`:
- **AI/** — narrators + the Analysis pipeline (see *AI narration pipeline* below).
- **Run/** — ingest (Strava activity → `ActivityDetail` + streams), metrics (`TrainingLoad`, `PersonalRecords`, VDOT/threshold estimators, `WeeklyAggregator`), and story (`Vibe`, `Temari`, `BriefingComposer`, `RunCardFactory`).
- **Gamification/** — `EquippedAccessories`, `GoalResolver`, `WeeklyRecapBuilder` (plus `DetectActivityMilestonesAction` and `GrantEligibleUnlocksAction` under `app/Actions/Gamification/`).
- **Strava/** — OAuth client, activity fetch, webhook + sync orchestration.
- **Geo/** — polyline encode/decode + Nominatim reverse-geocode (`app/Jobs/Geo/` resolves location names).
- **Weather/** — Open-Meteo snapshot attached per activity.

Two DB connections: default `mysql` plus a second **`analytics`** schema for metering (e.g. `ai_token_usages`); its migrations live in `database/migrations/analytics/`. Pages live under `resources/js/pages/`: `Today` (dashboard), `Activities/{Feed,Calendar}`, `Collection/{Cards,Records,Accessories}`, `Runs/Show`.

## Voice & copy

- **No em-dashes (`—`)** in UI copy *or* LLM prompt strings — they read as an AI/translation tell. Use commas, periods, colons, or `·`. (The `'—'` glyph as a *null placeholder* in data display is fine.)
- Temari is a training partner who keeps score, not a soft cheerleader: warm, but competitive about the user's own numbers (never against other runners), willing to name a coast once and plainly, and stingy with praise so it means something when given. Her narrated voice leans lowercase (a soft tendency, not a rule) and dry-funny; **UI chrome stays Title Case** and does not adopt the lowercase tendency. Shared across both: plain running-domain vocabulary (`pace`, `HR`, `km`, `TRIMP`, `splits`), a jargon-accessibility tier for technical terms, a `**bold**` emphasis rule, and a tight emoji rule (zero by default, one max, only for a genuine PR/first-ever, glyphs limited to 🔥/✨/🛌).
- Full rules: [docs/voice-and-tone.md](../../../docs/voice-and-tone.md). Persona source of truth: [TemariPersona.php](../../../app/Services/AI/TemariPersona.php). Read it before writing or reviewing copy.

## Design system

Pewter: cold near-white paper, near-black structure, lime accent. Tokens live in the `@theme` block of
[resources/css/app.css](../../../resources/css/app.css), which is *generated* by
[build-tokens.mjs](../../../resources/brand/build-tokens.mjs); full reference (colors, type scale,
fonts, radius, elevation, spacing) in [docs/design-tokens.md](../../../docs/design-tokens.md).
Use the **semantic token families, never raw Tailwind colors** like `lime-500`:

- `sky` (`#171f28`) / `sky-deep` (`#0b1017`) / `sky-2` (`#26303d`) — structure, dark hero panels, and (since F2) the dark ground itself. Cold near-black.
- `horizon` / `horizon-deep` (`#ade047` lime) — primary CTA, "earned"/PR state, Temari accent.
- `cream` / `cream-deep` (`#f1f5f8`) — paper / secondary surface and borders. Cold near-white.
- `ink` / `ink-2` / `ink-3` — the 3-tier text-contrast scale (see below).
- `surface` / `surface-card` / `surface-elev` / `surface-warm` / `surface-sunken` + `line` / `line-strong` — app surfaces (dawn-shift drifts `surface`).
- `mood-{blazing,easy,wobbly,gassed,overloaded,chill}` (each with a pastel `-bg` cell tint and an `-ink` label variant) — calendar cells + mood badges.
- `rarity-{common,uncommon,rare,epic,legendary}` (each with an `-ink` label variant) — card rarity.
- semantic hues `leaf` / `leaf-deep` / `leaf-ink`, `ember` / `ember-deep` / `ember-ink`, `citrus` / `citrus-ink`, `stone` (`-deep` fills a dark CTA, `-ink` carries the label; `citrus` fills no CTA and has no `-deep`).
- `strava-orange` / `strava-orange-hover` — reserved, never themed (see below).

`citrus` (`#c9971f`) is reserved for PR / legendary celebrations only.

**Two grounds, since F2.** `[data-theme="dark"]` on `<html>` inverts Sky and Cream — Sky becomes
ground, Cream becomes text — and is the app's **default** ground; light and system are reachable
from Settings. A second semantic layer (`background`/`foreground`/`card`/`popover`/... plus
`leaf-ink`/`ember-ink`/`citrus-ink`/`rarity-*-ink`, which invert per ground) sits above the palette
above; see "Ground-reactive semantic layer" in [docs/design-tokens.md](../../../docs/design-tokens.md).

**Fill vs text.** Every saturated family ships as a pair: the vivid value is the fill (dots,
frames, strokes, tinted cells), the derived `-ink` value is the only member allowed to carry text
or an icon on paper. `text-rarity-legendary` is always wrong; it is `text-rarity-legendary-ink`, and
`text-leaf-deep` / `text-ember-deep` / `text-horizon-deep` are wrong the same way. The two fills too
light to reach 3:1 (legendary gold, uncommon green) keep their vibrancy and are drawn with a 2px
`-ink` outline rather than being darkened. On a **dark** ground the split inverts: the vivid fill is
the readable label there (`text-leaf` on a sky panel), so an `onSky` branch keeps it.

**Radius, elevation, spacing** are scales now, not call-site guesses: `rounded-md` (14px) is the
card corner, `shadow-e1`..`e4` is resting → floating → sheet → modal (warm-tinted, never
Tailwind's neutral defaults), and padding names a role (`.pad-chip` / `.pad-panel` / `.pad-card` /
`.pad-hero` / `.pad-page`). `npm run check:palette` rejects raw palette shades, default shadows and
off-scale radii; `/devtools/design` renders the whole set plus a live contrast audit read out of
the shipped CSS.

### Strava brand mark — hands off

The "Connect with Strava" button (and any Strava brand mark) is never restyled. Strava brand
orange `#FC4C02` / hover `#E34402` are reserved via `--color-strava-orange` tokens. Within any card
that **displays the Strava brand mark**, keep other warm accents off it: switch the local context
to neutral (`surface-sunken` + `ink`) so the brand mark gets breathing room. Strava can revoke API access for brand-guideline violations.

### CTA contrast rule (WCAG)

`horizon` (`#ade047`) is a lime tone, so it pairs with **dark** text, never white. Follow the
[`PillButton`](../../../resources/js/components/ui/PillButton.tsx) presets:
- `horizon` bg → `text-foreground` (dark ink on lime clears 11.5:1); hover darkens to `horizon-deep`.
- `sky` / `sky-deep` bg (near-black) → `text-cream` / white text (passes ~15:1+); hover darkens to `sky-deep`.
- `leaf-deep` (`#256f4d`) / `ember-deep` (`#8d2c3d`) bg → `text-cream` (both pass AA); used for dense "retry"/action chips. No darker step exists, so darken on hover with `hover:opacity-90`, not a hue jump.
- Never put white text on `horizon`/`citrus`/`cream` (all too light).

### Gradient primitives

Gradient **text** is applied via
[`<GradientText preset="horizon|cream-sun" fontSize=… />`](../../../resources/js/components/ui/GradientText.tsx),
which clips a `linear-gradient` to the text via inline `background-clip`. Rule: **gradient text
on numbers only**, only at large display sizes, and only one per visible viewport. Scarcity makes
it feel premium, not Las-Vegas. Backdrop atmospherics (e.g. the login page) are inline CSS
`linear-gradient` + `radial-gradient` layers on the sky→horizon ramp, not a shared component;
in-app pages stay clean.

### Dawn-shift theme

[`useDawnShift`](../../../resources/js/hooks/useDawnShift.ts) is mounted in
[AppShell](../../../resources/js/layouts/AppShell.tsx); it writes
`data-time-of-day="dawn|morning|day|dusk|night"` on `<body>` so CSS surface tints respond to the
user's local time. Light mode only — never auto-flips to dark mode.

### Text contrast tiers

3-stop semantic system — use the tier that matches the text role, not "pick whichever color looks right".
Since F3, call sites write the ground-reactive semantic classes (backed by `--color-ink` on the
light ground, `--color-cream` on dark) rather than the raw `text-ink*` utilities, which still exist
underneath but are fixed to the light value:

- `text-foreground` (`#16181b` on light) — **primary text**: body paragraphs, headings, button labels, KPI values. Default for any prose the user reads.
- `text-text-2` (`#34373c` on light) — **supporting body**: page subtitles, briefing suggestion lines, descriptive paragraphs adjacent to a primary statement.
- `text-text-3` (`#60666d` on light) — **labels-above-values, timestamps, footnotes, table column headers, secondary metadata**. Smallest contrast tier, never use for body prose.

Sweep `grep text-text-3` before merging — if it's wrapping a `<p>` of running prose, it's probably wrong.

### Typography & fonts

Three families (all loaded via Google Fonts in
[app.blade.php](../../../resources/views/app.blade.php)): **Fraunces** italic is
`font-serif` (headlines + Temari voice/quotes; renamed from `font-display` in F3 to match the
prototype's own token name); **Plus Jakarta Sans** is `font-sans`, the default
family for body/UI/buttons; **JetBrains Mono** is `font-mono`, for *numbers, stats and small
uppercase metadata labels* (section labels, chips, stat-tile / kartu captions, timestamps). Oswald
(`font-collectible`) is retired: the Kartu uses the same stack as everything else. Because `font-sans` is Tailwind's default, every small uppercase label must carry an
**explicit `font-mono`** (or the `.text-label-micro` / `.text-label-small` utilities) or it falls back to
the sans. Keep `tabular-nums` on numeric / stat displays.
The scale is fluid `clamp()` tokens in `app.css` (`text-display-*`, `text-headline-*`,
`text-quote-*`), each bundling its own line-height + letter-spacing, so one utility lands the full spec.

| Role | Class |
|---|---|
| In-app hero title | `font-serif italic text-display-2xl text-foreground` |
| Page title (`<h1>`) | `font-serif text-display-lg text-foreground` (compact/devtools header: `text-headline-xs`) |
| Section heading (`<h2>`) | `font-serif text-headline-sm text-foreground` |
| Temari voice / quote | `font-serif italic text-quote-lg text-text-2` |
| Sub-label (KPI/table cap) | `font-mono text-xs font-semibold uppercase tracking-wider text-text-3` |
| Body paragraph | `font-sans text-sm leading-relaxed text-foreground` |
| Caption / supporting | `text-sm text-text-2 leading-relaxed` |
| Meta / timestamp | `text-xs text-text-3` |
| KPI / big stat value | display tier (`text-display-xs`+) `tabular-nums text-foreground`; avoid one-off `text-[NNpx]` |

### Section spacing rhythm

- Major section → next major: `mt-10`
- Subsection → next: `mt-6`
- `<h2>` → content: `mt-3`
- Page header → first section: `mt-8`
- Card padding names a role, never a number: `.pad-hero` (24px) for hero cards, `.pad-card` (16px) for data cards, `.pad-panel` (12/16px) for dense rows, `.pad-chip` for chips and pills

## AI narration pipeline

Every narrated block flows: **Narrator → Analyze\*Job → Analysis row → AnalysisType → AnalysisController → UI (AnalysisStatus)**.
The failure model, idempotency guard, and unconfigured-env fallback are documented in the
always-on guideline ("LLM Integration" in CLAUDE.md).

### Adding a new narrated block — all 6 wires

Miss one and it fails loudly: `php artisan` breaks on enum match exhaustiveness (PHPStan), or
the structure / coverage gates fail. **Model the shape on an existing sibling and mirror it** —
per-user-per-day follows `TrendCaption`; per-activity follows `RunInsight*`; per-row-model
follows `WeeklyRecap` / `PrContext` / `CardFlavor`. Let `Name` = StudlyCase, `snake` = snake_case.

1. **Narrator** — `app/Services/AI/Narrators/{Name}Narrator.php`. Inject `StructuredChatCaller`;
   expose `generate(...)` returning the narrated string. Build `$context` from real metrics
   (route any pace through `App\Services\Run\Metrics\PaceCalculator`). No em-dashes in the prompt.
2. **Job** — `app/Jobs/AI/Analyze{Name}Job.php` extending `AnalyzeRowJob` (single row) or
   `AnalyzeGroupJob` (multi-row). Row job: override `generateContent()` to resolve the subject and
   call the narrator (see `AnalyzeTrendCaptionJob`). Group job: override `generateAll()` to resolve
   the subject once and return the per-type payload (see `AnalyzeBriefingJob`).
3. **AnalysisType** — `app/Services/AI/AnalysisType.php`: add `case {Name} = '{snake}';`; if the
   subject is a synthetic user/day/month key (not an Eloquent model) add a `*_SUBJECT_TYPE` const
   and return it from `subjectType()`, otherwise return the model class; add the `jobClass()` arm.
4. **AnalysisSubjectAuthorizer** — add the `authorize()` match arm in
   `app/Services/AI/AnalysisSubjectAuthorizer.php`: user-scoped → `$subjectId === $user->id`;
   model-scoped → `self::userOwns(...)`.
5. **Aggregate suites** — register the narrator in
   `tests/Unit/Services/AI/Narrators/NarratorsCoverageTest.php` and the job in
   `tests/Unit/Jobs/AI/JobsCoverageTest.php`. The structure test exempts these namespaces on the
   basis that these suites cover them.
6. **Frontend** — render the block through `resources/js/components/temari/AnalysisStatus.tsx` on
   the page that shows it, so pending / failed / retry states are handled.

Then run `./vendor/bin/sail composer check` and fix anything red.

**Not every AI surface is a narrated block.** The scoped per-run Q&A stores its own
`run_questions` rows and dispatches its own job instead of using the Analysis row model —
one run holds many questions, which `(subject, type, discriminator)` cannot key. It still
goes through `StructuredChatCaller` and a bound-at-construction toolbox, so persona,
budget, retries and metering are unchanged. See `docs/decisions/scoped-run-qa-not-an-analysis-row.md`
before reaching for a new `AnalysisType` on anything user-initiated and free-form.

## Testing

- **1:1 class↔test.** Every concrete class has a `{Name}Test.php`, or is exempt in [tests/Unit/Architecture/EveryClassHasATestTest.php](../../../tests/Unit/Architecture/EveryClassHasATestTest.php). Frontend: co-located `{name}.test.tsx`, guarded by [resources/js/test/structure.test.ts](../../../resources/js/test/structure.test.ts).
- **Aggregate suites** cover whole families: narrators → `NarratorsCoverageTest`, AI jobs → `JobsCoverageTest`. A new narrator/job must be registered there.
- Structure tests live in the `structure` group and run **before** coverage in CI (fast fail). Gate: 95% line+function coverage.
- **DB isolation is per-file opt-in**, not global. A DB-touching test adds `uses(RefreshDatabase::class)`; the architecture/enum/geo/calculator tests stay DB-free on purpose, and the CI `structure` gate runs with **no DB** — so a suite-wide `uses(...)->in('Feature','Unit')` breaks them (they'd connect to an unreachable host and the gate fails). `RefreshDatabase` transacts both the default `mysql` and the second `analytics` connection (`$connectionsToTransact` in [tests/TestCase.php](../../../tests/TestCase.php), which rebinds `analytics` to the test DB in `setUpTraits`). Do **not** switch to `LazilyRefreshDatabase`: its deferred, manager-level trigger doesn't wrap the purged-and-rebound `analytics` connection, so `ai_token_usages` writes leak across tests.
- **Test speed lives in fixtures, not the trait.** The suite is parallel and DB-bound; the long pole is heavy-seed tests, not transaction overhead. The clearest case was `DemoSeedCommandTest` re-running the full ~126-run demo seed once per assertion-test (~52% of serial runtime) — consolidated to seed once and assert many (the idempotency test keeps its own two seeds), cutting `--processes=4` wall-clock ~27%. Mocking a class's injected siblings does **not** cut time: services/jobs own their DB access (`Model::query()` in their own bodies; jobs resolve their model in `handle()`), and controllers can't shed the DB because [HandleInertiaRequests](../../../app/Http/Middleware/HandleInertiaRequests.php) queries the auth user's shared props on every page load. Frontend `fetch` defaults to a safe 404 stub in [resources/js/test/setup.ts](../../../resources/js/test/setup.ts); override per-test only when asserting specific calls.

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

**Pest 5 TIA is on for every local run** (`pest()->tia()->locally()` in [tests/Pest.php](../../../tests/Pest.php), backed by
pcov in the dev image). Unaffected tests are **replayed from a cached dependency graph** rather than
executed, so a run after a small change costs a fraction of the full suite. Two consequences worth
internalising:

- A green `--filter=Name` with nothing changed is a *cached* pass, not a fresh execution. Pass
  **`--no-tia`** when you need to genuinely re-run, or `--fresh` to discard the graph and re-record.
- TIA is coverage-driven, so tests that read the filesystem (`File::allFiles`, `glob`) record no
  edges. `tests/Unit/Architecture` is therefore pinned to run on any `app/`, `tests/`, `docs/` or
  `resources/css/` change via the `watch()` map — extend that map when adding another scanning test,
  or the gate silently stops firing.

CI passes `--no-tia` on both Pest steps: a narrowed run would quietly shrink the 95% coverage gate.

TIA works in **worktrees** too, but only because `worktree-setup.sh` writes a `compose.override.yaml`
that mounts the shared git dir and exports `GIT_DIR` — a worktree's `.git` is a *file* pointing at a
host path outside the bind mount, and TIA panics on an unresolvable repo rather than degrading. A
worktree stack brought up without that override falls through to TIA off (the `tests/Pest.php` guard),
which is degraded but not broken. Each worktree records its own graph from cold on first run.

**Dev commands:**
- After changing a PHP enum exposed to TS: `./vendor/bin/sail artisan typescript:enums` (`--check` mirrors CI).
- Local UI/demo data (deterministic, no LLM tokens, no Strava HTTP): `./vendor/bin/sail artisan demo:seed`. Idempotent, re-run any time to converge. It only upserts the current blueprint set, so to purge rows from retired blueprints do a full reset: `./vendor/bin/sail artisan migrate:fresh` then `demo:seed`.

## Parallel worktrees & stacked PRs

Running 2-3 Claude Code agents concurrently, each in its own `git worktree`, is safe — Compose
derives its project name (containers/network/volumes) from the checkout's **directory basename**,
and `compose.yaml` has no hardcoded `name:`/`COMPOSE_PROJECT_NAME`, so every worktree already gets
its own isolated stack for free. `mysql`/`redis` are never published to the host at all (only
reached via `sail mysql`/`sail artisan tinker`/`docker exec`), so the only real collision is
**fixed host ports** (`.env.example`'s `APP_PORT`/`VITE_PORT`), which two worktrees would both try
to bind off an unmodified `.env`. No changes needed to `compose.yaml` or `.githooks/pre-commit` —
the pre-commit hook's `docker compose ps` check already resolves per-cwd correctly.

Workflow: `EnterWorktree name=<slice>` → `./scripts/worktree-setup.sh <slot 1|2|3>` → normal
fast-feedback ladder → `ExitWorktree action=remove|keep`. The script takes its own
`APP_PORT`/`VITE_PORT` off a static slot table (main stays 7001/7002, slots use 701x/702x), writes
an untracked `compose.override.yaml` mounting the shared git dir so TIA works, brings the stack up,
fixes cache-volume ownership, then bootstraps the app: `composer install`, `key:generate`, and
**both** migration sets. Every step is guarded or idempotent, so re-running the script after a
failure is safe. `vendor/` is empty when it starts, so it uses plain `docker compose exec` for all
of it; `./vendor/bin/sail` works for everything afterwards.

**Both** migration sets matters. `analytics` is a second connection with its own migration path, so
a plain `artisan migrate` does not touch it — the script also runs
`migrate --database=analytics --path=database/migrations/analytics`. Without it `strava_sync_logs`
and `ai_token_usages` are missing and `/pulse` + `/ai-usage` 500. This lived only in the script's
printed next-steps until #614, which is exactly why every worktree skipped it.

The PHP suites are ready at that point (they self-initialize their own `mysql_test`/`redis_test`).
To *load a page in a browser*, also run `./vendor/bin/sail npm ci` plus `sail npm run dev` (or
`npm run build`).

Composer's and npm's **download caches** are shared across worktrees via fixed-name volumes
(`temari_composer_cache`/`temari_npm_cache` in `compose.yaml`) — only `vendor/`/`node_modules`
themselves stay per-worktree (each must reflect that branch's own lockfile), so the second+
worktree's install just replays from cache instead of re-downloading over the network.
`worktree-setup.sh` chowns all three cache-type volumes (`node_modules` included) to `www-data`
right after bringing the stack up, since they're created root-owned on first boot and the container
always runs as `www-data` — no manual fix needed.

**One fresh-worktree gotcha**, not concurrency-specific: if several worktrees cold-install at the
same moment, one can occasionally fail mid-extraction on a transient bind-mount visibility race —
just re-run `worktree-setup.sh`, which resumes rather than redoing. (The old `MissingAppKeyException`
gotcha is gone: the script generates the key itself, and only when `APP_KEY` is unset, so a re-run
never rotates it out from under a live session.)

The Docker image (`temari/dev`) and its build cache are shared across worktrees on purpose (plain
local tag, not project-scoped) — only pass `--build` again if a worktree's slice actually touches
`Dockerfile`/PHP extensions, so two worktrees don't race an in-flight rebuild. Each worktree gets
its own full DB/Redis stack rather than sharing one — cheap (dev-tuned, tmpfs test DBs), and
sharing would let one agent's `migrate:fresh`/paratest run wipe or lock schema state another
agent's test run depends on mid-flight.

**Sequential (dependency-wave) slices** — when wave N+1 must branch off wave N's *unmerged* code —
don't fit plain parallel worktrees (`EnterWorktree`'s base ref is `origin/main` by default). Branch
manually instead: `git worktree add .claude/worktrees/<name> wave1-branch`, then
`EnterWorktree path=.claude/worktrees/<name>` to adopt it for cleanup tracking. This is the one
case where GitHub's native **stacked PRs** (public preview since 2026-07-30, `gh extension install
github/gh-stack`) are worth reaching for: each layer's PR targets the layer below instead of
`main`, and merging a lower layer auto-cascades the merge/rebase of everything above. Don't reach
for it otherwise — most PRs in this repo are small, independent, and based directly off `main`;
stacking a truly independent slice just adds process for no payoff. It's also free on CI: every
job except `deploy` in `ci.yml` runs on hosted `ubuntu-latest`, and `deploy` only fires on
`push: branches: [main]` — a stack's intermediate branches never touch the shared 4-core homelab
runner. Merging an N-deep stack does mean N sequential prod deploys back-to-back (queued via
`deploy-prod`'s concurrency group, not parallel) — expected, not a CI misfire.

## Inspecting real state

No MCP server; use the toolchain directly. Prefer these over guessing:
- **Data** — `./vendor/bin/sail artisan tinker --execute '...'`, or `./vendor/bin/sail mysql`.
- **Schema** — `./vendor/bin/sail artisan db:show --counts`, `db:table <name>`.
- **App errors** — `./vendor/bin/sail logs -f`, or `storage/logs/laravel-*.log` (daily rotation).
- **React/Inertia console errors** — browser devtools, or the `browser-review` scripts, which
  capture `console`/`pageerror` per page across the viewport matrix.
- **Framework APIs** — read the installed source under `vendor/` rather than recalling; this
  stack (Laravel 13 / Inertia v3 / React 19 / Tailwind v4 / Pest 4) drifts fast.
