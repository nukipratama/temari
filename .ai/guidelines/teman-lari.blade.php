# teman-lari project guidelines

## Stack notes

- UI is **Inertia 2 + React 19 + TypeScript + Tailwind v4** (Laravel React Starter Kit conventions). Routes go through controllers (`Inertia::render('PageName', $props)`); pages live in `resources/js/pages/`, components in `resources/js/components/`.
- `livewire/livewire` ships for Pulse internals only. **Do NOT use Livewire in app code.**
- `openai-php/laravel` is wired as an **Azure OpenAI** client. `inertiajs/inertia-laravel` v3 speaks the **Inertia 2** protocol.
- App is **light-mode only**: `.dark` is never applied to `<html>` and there are no `*-dark` tokens. Write light-only.
- A second **`analytics`** DB connection (separate schema, same MySQL server) holds metering tables (e.g. `ai_token_usages`). Its migrations live in `database/migrations/analytics/` and run via `--database=analytics --path=...`; in tests it shares the default test DB (see [tests/TestCase.php](tests/TestCase.php)). Beyond `AI`, backend logic is split by domain under `app/Services/` (`AI`, `Run`, `Gamification`, `Strava`, `Geo`, `Weather`); see the `teman-lari` skill for the full map.

> **Design tokens (Daybreak palette), voice & tone, typography, the AI narrator pipeline, the 1:1 test convention, and the Sail toolchain all live in the `teman-lari` skill** (`.claude/skills/teman-lari/`). Activate it for any UI, AI-narration, or test work. Source-of-truth docs: [docs/design-tokens.md](docs/design-tokens.md), [docs/voice-and-tone.md](docs/voice-and-tone.md).

## Knowledge base (`docs/`)

The human-facing knowledge base lives in `docs/` as `[[wikilinked]]` notes (a frontmatter template per [docs/_template.md](docs/_template.md), a MOC per section, [docs/DESIGN.md](docs/DESIGN.md) as the apex). Read it to understand a feature/subsystem before broad-exploring the code; navigate by grep + the MOCs. A wikilink `[[x]]` resolves to the file `docs/**/x.md` (folder-form like `[[features/index]]` is a direct path) — open it with that glob; code links use root-relative paths.

- **Curated reference, not a diary.** Only features and *architecturally significant* decisions earn a note. No per-commit / work-log / changelog notes, that's git history + PR descriptions.
- **Cite code by `path:line`, never transcribe it.** A CI guard ([scripts/check-doc-citations.php](scripts/check-doc-citations.php)) fails the build if a doc cites a path that no longer exists.
- **Keep notes fresh in the same PR.** If a change makes an *existing* doc wrong, fix it alongside the code (don't write a new note per PR).
- **ADRs in `docs/decisions/` are immutable**: supersede with a new dated note, don't rewrite history.

## Common commands

Everything runs in Docker via **Sail** (no host PHP/Node). Stop at the first failure on the fast-feedback ladder; the full skill toolchain has the rest.

```bash
./vendor/bin/sail up -d                      # start the stack
./vendor/bin/sail pest --group=structure     # fast 1:1 + aggregate structural gate (run first)
./vendor/bin/sail bin pest --filter=Name     # a single test / file while iterating
./vendor/bin/sail bin pest --parallel        # full PHP suite
./vendor/bin/sail npm run test               # frontend (Vitest); `test:coverage` for the 95% gate
./vendor/bin/sail npm run build              # build assets (`npm run dev` for HMR)
./vendor/bin/sail bin pint                    # format PHP (pre-commit also runs phpstan + rector)
./vendor/bin/sail composer check             # full gate: pint + phpstan + rector + pest + tsc + vitest (pre-push)
```

## LLM Integration

Briefing and analysis narration is LLM-backed via Azure OpenAI through openai-php/laravel ([AzureOpenAIClient](app/Services/AI/AzureOpenAIClient.php), [StructuredChatCaller](app/Services/AI/StructuredChatCaller.php), narrators under [app/Services/AI/Narrators/](app/Services/AI/Narrators/)). All narrator output flows through the [Analysis](app/Models/AI/Analysis.php) row model (status: pending / queued / processing / done / failed).

**Runtime failure model**: when an AI job exhausts its `$tries` (Laravel native retry + backoff on [AnalyzeBaseJob](app/Jobs/AI/AnalyzeBaseJob.php)), it lands in `failed_jobs` and the Analysis row is marked `failed`. The UI shows an empty state with a "Coba lagi" button per-block via [AnalysisStatus.tsx](resources/js/components/temari/AnalysisStatus.tsx). Users manually re-dispatch from the UI; developers can retry via Horizon's failed-job tab. AI jobs early-exit when the row is already `done` (idempotency guard in [AnalyzeRowJob](app/Jobs/AI/AnalyzeRowJob.php) and [AnalyzeGroupJob](app/Jobs/AI/AnalyzeGroupJob.php)) so a UI-triggered retry that races with a developer Horizon retry doesn't double-bill the LLM. **Failed analysis blocks are never auto-retried**, retry is manual (UI "Coba lagi" or Horizon) to keep LLM cost predictable. Scheduled commands do exist ([routes/console.php](routes/console.php)): `ai:daily-briefing` at 00:01 is the daily kickoff (briefing set + TrendCaption), and `strava:sync` is the fallback poll behind the webhook, neither re-dispatches a failed block. **There is no global "mode darurat" chip**, per-block state is the source of truth.

**Unconfigured-env fallback**: when `AZURE_OPENAI_URI` / `AZURE_OPENAI_API_KEY` are empty (dev/demo/local without credentials), [AnalysisService](app/Services/AI/AnalysisService.php) skips job dispatch entirely. Rows stay pending until something fills them.

**Demo seed**: [DemoSeedCommand](app/Console/Commands/DemoSeedCommand.php) backfills every Analysis row with deterministic rule-based content via [RuleBasedNarrationFiller](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php) under `AnalysisService::withoutDispatching()`, no LLM tokens spent on seed. The "Baca ulang" button stays live so a reviewer can trigger a real LLM call per block on demand.

## Environment toggles

- `DEMO_LOGIN_ENABLED` (default `false`): renders the "Coba versi demo" button on `/login` that signs in as the seeded demo user. Plumbed via [config/demo.php](config/demo.php) to Inertia shared `demoLoginEnabled`. Loaded in prod from the host `.env` via [compose.prod.yaml](compose.prod.yaml) `env_file:` ([ci.yml](.github/workflows/ci.yml) rolls the services, it does not inject these values).

## Debugging

When a bug or error is reported, ground the investigation in real state via Boost MCP before hypothesizing: `last-error` + `read-log-entries` for server errors, `browser-logs` for React/Inertia console errors, `database-query` for data. Full tool list in the `teman-lari` skill.
