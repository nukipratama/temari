# teman-lari project guidelines

## Stack notes

- UI is **Inertia 2 + React 19 + TypeScript + Tailwind v4** (Laravel React Starter Kit conventions). Routes go through controllers (`Inertia::render('PageName', $props)`); pages live in `resources/js/pages/`, components in `resources/js/components/`.
- `livewire/livewire` ships for Pulse internals only. **Do NOT use Livewire in app code.**
- `openai-php/laravel` is wired as an **Azure OpenAI** client. `inertiajs/inertia-laravel` v3 speaks the **Inertia 2** protocol.
- App is **light-mode only**. The `dark:` modifier still appears in legacy components but `.dark` is never applied to `<html>`, and there are no `*-dark` tokens. Treat new code as light-only.

> **Design tokens (Daybreak palette), voice & tone, typography, the AI narrator pipeline, the 1:1 test convention, and the Sail toolchain all live in the `teman-lari` skill** (`.claude/skills/teman-lari/`). Activate it for any UI, AI-narration, or test work. Source-of-truth docs: [docs/design-tokens.md](docs/design-tokens.md), [docs/voice-and-tone.md](docs/voice-and-tone.md).

## LLM Integration

Briefing and analysis narration is LLM-backed via Azure OpenAI through openai-php/laravel ([AzureOpenAIClient](app/Services/AI/AzureOpenAIClient.php), [StructuredChatCaller](app/Services/AI/StructuredChatCaller.php), narrators under [app/Services/AI/Narrators/](app/Services/AI/Narrators/)). All narrator output flows through the [Analysis](app/Models/AI/Analysis.php) row model (status: pending / queued / processing / done / failed).

**Runtime failure model**: when an AI job exhausts its `$tries` (Laravel native retry + backoff on [AnalyzeBaseJob](app/Jobs/AI/AnalyzeBaseJob.php)), it lands in `failed_jobs` and the Analysis row is marked `failed`. The UI shows an empty state with a "Coba lagi" button per-block via [AnalysisStatus.tsx](resources/js/components/temari/AnalysisStatus.tsx). Users manually re-dispatch from the UI; developers can retry via Horizon's failed-job tab. AI jobs early-exit when the row is already `done` (idempotency guard in [AnalyzeRowJob](app/Jobs/AI/AnalyzeRowJob.php) and [AnalyzeGroupJob](app/Jobs/AI/AnalyzeGroupJob.php)) so a UI-triggered retry that races with a developer Horizon retry doesn't double-bill the LLM. **There is no scheduler**, manual retry only, to keep LLM cost predictable. **There is no global "mode darurat" chip**, per-block state is the source of truth.

**Unconfigured-env fallback**: when `AZURE_OPENAI_URI` / `AZURE_OPENAI_API_KEY` are empty (dev/demo/local without credentials), [AnalysisService](app/Services/AI/AnalysisService.php) skips job dispatch entirely. Rows stay pending until something fills them.

**Demo seed**: [DemoSeedCommand](app/Console/Commands/DemoSeedCommand.php) backfills every Analysis row with deterministic rule-based content via [RuleBasedNarrationFiller](app/Services/AI/Demo/RuleBasedNarrationFiller.php) under `AnalysisService::withoutDispatching()`, no LLM tokens spent on seed. The "Baca ulang" button stays live so a reviewer can trigger a real LLM call per block on demand.

## Environment toggles

- `DEMO_LOGIN_ENABLED` (default `false`): renders the "Coba versi demo" button on `/login` that signs in as the seeded demo user. Plumbed via [config/demo.php](config/demo.php) to Inertia shared `demoLoginEnabled`. Wired in [compose.prod.yaml](compose.prod.yaml) + [ci.yml](.github/workflows/ci.yml) deploy env.
- `ONBOARDING_FORCE_SHOW` (default `false`): forces the dashboard first-run tooltip to render on every mount regardless of run count or the localStorage dismissal flag. Used for QA / demos in prod. Plumbed via [config/onboarding.php](config/onboarding.php) to Inertia shared `onboarding.forceShow`. Wired in [compose.prod.yaml](compose.prod.yaml) + [ci.yml](.github/workflows/ci.yml) deploy env.
