<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/framework (LARAVEL) - v13
- laravel/horizon (HORIZON) - v5
- laravel/prompts (PROMPTS) - v0
- laravel/pulse (PULSE) - v1
- inertiajs/inertia-laravel (INERTIA) - v3 (Inertia 2 protocol)
- openai-php/laravel (OPENAI) - v0 (Azure OpenAI client)
- livewire/livewire (LIVEWIRE) - v4 (Pulse internal only — NOT used in app code)
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- laravel/telescope (TELESCOPE) - v5
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- rector/rector (RECTOR) - v2
- tailwindcss (TAILWINDCSS) - v4
- react - v19 + typescript (Inertia pages in resources/js/pages/**)
- react-chartjs-2, chart.js, @iconify/react

## Frontend Stack

UI is **Inertia 2 + React 19 + TypeScript + Tailwind v4** following Laravel React Starter Kit conventions. Routes still go through controllers (`Inertia::render('PageName', $props)`); React pages live in `resources/js/pages/` and components in `resources/js/components/`. Palette tokens live in the `@theme` block of [resources/css/app.css](resources/css/app.css) — components use `brand-*`, `accent-*`, `pop-*`, `surface*`, `ink*`, `mood-*` semantic classes, NOT raw Tailwind colors like `lime-500`. Brand primary is deep emerald `#0E7A4C`; co-primary accent is warm terracotta `#D9764A`; `pop-*` mustard is reserved for PR / legendaris celebrations only. App is **light-mode only** — the `dark:` modifier still appears in legacy component code but `.dark` is never applied to `<html>`; treat any new code as light-only.

### Strava brand mark — hands off

The "Connect with Strava" button (and any Strava brand mark in the app) is never restyled. Strava brand orange `#FC4C02` / hover `#E34402` are reserved via `--color-strava-orange` tokens. Terracotta `#D9764A` and Strava orange share a hue family, so within any card that **displays the Strava brand mark**, the surrounding `accent` color is *not* used — switch the local context to neutral (`surface-sunken` + `ink`) so the brand mark gets breathing room. Strava can revoke API access for brand-guideline violations.

### CTA contrast rule (WCAG)

`accent-500` `#D9764A` on white text is ~3.4:1 — passes for large text (≥18px, or 14px bold) but **fails AA for normal body text**. So:
- Primary CTAs on `accent-500` must be `text-base font-bold` or larger.
- Smaller / dense buttons use `brand-700` `#07492D` as bg (white text passes 12:1).
- `accent-500` is fine for icon-only buttons and large hero CTAs.

### Gradient primitives

Five signature gradients live as CSS vars in `app.css` (`--gradient-subuh`, `--gradient-subuh-soft`, `--gradient-fajar`, `--gradient-thread`, `--gradient-ember`), exposed as utilities `bg-gradient-*` and `text-gradient-*`. Rule: **gradient text on numbers only**, only on `text-2xl`+, and only one per visible viewport — scarcity makes it feel premium, not Las-Vegas. Use [`<GradientNumber>`](resources/js/components/GradientNumber.tsx) as the wrapper. Backdrop atmospherics use [`<MeshBackdrop variant="dawn|night|ember" />`](resources/js/components/MeshBackdrop.tsx) inside `relative overflow-hidden` parents.

### Dawn-shift theme

[`useDawnShift`](resources/js/hooks/useDawnShift.ts) is mounted in [AppShell](resources/js/layouts/AppShell.tsx); it writes `data-time-of-day="dawn|morning|day|dusk|night"` on `<body>` so CSS surface tints respond to user's local time. Light mode only — never auto-flips to dark mode.

### Text contrast tiers

3-stop semantic system — use the tier that matches the text role, not "pick whichever color looks right":

- `text-ink` (+ `dark:text-ink-dark`) — **primary text**: body paragraphs, headings, button labels, KPI values. Default for any prose the user reads.
- `text-ink-soft` (+ `dark:text-ink-soft-dark`) — **supporting body**: page subtitles, briefing suggestion lines, descriptive paragraphs adjacent to a primary statement.
- `text-ink-meta` (+ `dark:text-ink-meta-dark`) — **labels-above-values, timestamps, footnotes, table column headers, secondary metadata**. Smallest contrast tier — never use for body prose.

Sweep `grep text-ink-soft` before merging — if it's wrapping a `<p>` of running prose, it's probably wrong.

### Typography scale

| Role | Class |
|---|---|
| Hero title (pre-auth landing) | `text-4xl font-semibold tracking-tight` |
| Page title (`<h1>`) | `text-2xl font-semibold tracking-tight` |
| Section heading (`<h2>`) | `text-lg font-bold tracking-tight` |
| Sub-label (KPI/table cap) | `text-xs font-semibold uppercase tracking-wider text-ink-meta` |
| Body paragraph | `text-base leading-relaxed text-ink` |
| Caption / supporting | `text-sm text-ink-soft leading-relaxed` |
| Meta / timestamp | `text-xs text-ink-meta` |
| KPI value | `text-3xl font-black tabular-nums text-ink` |
| Card stat (RunCard) | `text-2xl font-black tabular-nums text-ink` |

### Section spacing rhythm

- Major section → next major: `mt-10`
- Subsection → next: `mt-6`
- `<h2>` → content: `mt-3`
- Page header → first section: `mt-8`
- Hero card padding: `p-6`; data card padding: `p-4`; chip/pill: `px-3 py-1`

## LLM Integration

Briefing and analysis narration is LLM-backed via Azure OpenAI through openai-php/laravel ([AzureOpenAIClient](app/Services/AI/AzureOpenAIClient.php), [StructuredChatCaller](app/Services/AI/StructuredChatCaller.php), narrators under [app/Services/AI/Narrators/](app/Services/AI/Narrators/)). All narrator output flows through the [Analysis](app/Models/AI/Analysis.php) row model (status: pending / queued / processing / done / failed).

**Runtime failure model**: when an AI job exhausts its `$tries` (Laravel native retry + backoff on [AnalyzeBaseJob](app/Jobs/AI/AnalyzeBaseJob.php)), it lands in `failed_jobs` and the Analysis row is marked `failed`. The UI shows an empty state with a "Coba lagi" button per-block via [AnalysisStatus.tsx](resources/js/components/temari/AnalysisStatus.tsx). Users manually re-dispatch from the UI; developers can retry via Horizon's failed-job tab. AI jobs early-exit when the row is already `done` (idempotency guard in [AnalyzeRowJob](app/Jobs/AI/AnalyzeRowJob.php) and [AnalyzeGroupJob](app/Jobs/AI/AnalyzeGroupJob.php)) so a UI-triggered retry that races with a developer Horizon retry doesn't double-bill the LLM. **There is no scheduler** — manual retry only, to keep LLM cost predictable. **There is no global "mode darurat" chip** — per-block state is the source of truth.

**Unconfigured-env fallback**: when `AZURE_OPENAI_URI` / `AZURE_OPENAI_API_KEY` are empty (dev/demo/local without credentials), [AnalysisService](app/Services/AI/AnalysisService.php) skips job dispatch entirely. Rows stay pending until something fills them.

**Demo seed**: [DemoSeedCommand](app/Console/Commands/DemoSeedCommand.php) backfills every Analysis row with deterministic rule-based content via [RuleBasedNarrationFiller](app/Services/AI/Demo/RuleBasedNarrationFiller.php) under `AnalysisService::withoutDispatching()` — no LLM tokens spent on seed. The "Baca ulang" button stays live so a reviewer can trigger a real LLM call per block on demand.

## Environment toggles

- `DEMO_LOGIN_ENABLED` (default `false`) — renders the "Coba versi demo" button on `/login` that signs in as the seeded demo user. Plumbed via [config/demo.php](config/demo.php) → Inertia shared `demoLoginEnabled`. Wired in [compose.prod.yaml](compose.prod.yaml) + [ci.yml](.github/workflows/ci.yml) deploy env.
- `ONBOARDING_FORCE_SHOW` (default `false`) — forces the dashboard first-run tooltip to render on every mount regardless of run count or the localStorage dismissal flag. Used for QA / demos in prod. Plumbed via [config/onboarding.php](config/onboarding.php) → Inertia shared `onboarding.forceShow`. Wired in [compose.prod.yaml](compose.prod.yaml) + [ci.yml](.github/workflows/ci.yml) deploy env.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
