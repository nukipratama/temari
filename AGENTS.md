# AGENTS.md

This is the canonical project guidance shared by agents. Runtime entrypoints may add orchestration details without copying these rules.

## Shared agent workflow

- The layers of a stack are sequential work and happen in the main checkout. A runtime's native isolation is fine: any plain `git worktree add` triggers the `.githooks/post-checkout` hook, which runs `scripts/worktree adopt` to give the new worktree its own slot. If Docker was down at that moment, run `scripts/worktree adopt` inside the worktree yourself.
- For manual isolation, run `scripts/worktree create <name> [base]` to create a worktree (allocates a slot, branches from `base`, assigns ports, a `temari-slot<N>` Compose project and isolated schemas on the shared MySQL/Redis services, installs Composer and npm dependencies whenever the lockfiles differ from what is installed, runs both migration sets, builds assets so pages load straight away, and rolls back on failure) and `scripts/worktree remove <path>` to tear one down. Re-running `create` with the same name, or `adopt` on a reused worktree, re-runs setup and refreshes dependencies after `main` bumps a lockfile; `npm ci` wipes Playwright, so re-run the browser-review `setup.sh` afterwards.
- `scripts/worktree remove` deletes the worktree and its `worktree-<name>` branch. It refuses while the worktree has uncommitted changes, or while the branch has commits that are on no remote or other branch. Push the work first, or pass `--force` to discard it. It never deletes a branch that `adopt` set up, because `create` did not make that branch.
- Setup prints one line per step. Its full output goes to `storage/logs/worktree-setup.log` in the worktree, and the last lines are printed when a step fails.
- A worktree dropped with plain `git worktree remove` or `rm -rf` leaves its container, schemas and slot lock behind. `scripts/worktree prune` reclaims them, and the next `create`/`adopt` reclaims any abandoned slot it lands on. Git branches are never touched.
- Never `artisan tinker <file>`, use `--execute`. Stop tests, builds, and servers when their task ends.
- Multi-slice work ships as a GitHub stack (`gh stack`): one PR per layer, merged by the owner. Read the `temari` skill's "Stacked PRs" section before starting one.
- Work items, agent briefs and decisions live in GitHub issues and the [kanban board](https://github.com/users/nukipratama/projects/1), not in local files: find them with `gh issue list --label wave:*` and `gh issue view <n>`. Each settled decision is its own issue labelled `decision` (`gh issue list --label decision --state all`); an owner call still open is labelled `needs-decision`. A card moves Ready → In progress on dispatch → In review when its PR opens → Done on merge, and every PR carries `Closes #<n>`. `.planning/` is gitignored scratch space only.
- Long or multi-agent runs track progress with one writer per level. The main session owns the milestone checklist as checkboxes in the parent issue and ticks an item only after verifying the agent's report against real state. Each agent keeps its own `.planning/progress.md` in its worktree, ticks it as it goes, appends new findings, and never edits the issue or another agent's files. The main session files those findings as backlog issues.
- For plan/coaching policy calls (redistribution, clamps, grading), decide from established coaching practice, record the rationale in the PR or ADR, and build; ask the owner only about product, UX or infra trade-offs.
- Parallel briefs assign each agent its route paths and names, with no placeholder routes; the later PR checks `routes/` for duplicate paths after merging `main`.

### PR handoff standard

Every pull request is a reviewer handoff, not just a change list. Keep the description aligned with the issue and include:

- the user-visible outcome and the settled decision or acceptance criteria it implements;
- a concise map of the affected files/subsystems, including migrations, jobs, queues, backfills, or external-service effects;
- exact verification commands and their results, plus any checks that could not run;
- a reviewer path: fixtures, flags, routes, screenshots, or focused tests that make the behavior easy to reproduce; for a visual change, `gh pr edit <n> --attach '<file>#<alt>'` can upload the reviewed screenshots without a browser;
- for a change with motion or interaction (transitions, popovers, gestures, loading states), a short real-speed before/after clip on both grounds beside the screenshots, recorded per the `browser-review` skill's clips recipe;
- rollout, privacy, failure, rollback, and follow-up notes, including demo-data exclusions where relevant.

Use `Closes #<n>` in the PR body, keep issue/PR text free of secrets and identifying athlete data, and update the description when later pushes change scope or verification. The repository is public: describe athlete ids, emails, hostnames and per-athlete costs rather than pasting them.

A check blocks merging only when its job is in `ci.yml` under `ci-gate`'s `needs`; a standalone workflow is advisory. Give every new workflow job a `timeout-minutes`.

## External actions

- Read production runtime state from the live container (`artisan tinker --execute`, with per-use approval), never from the repo `.env` or config files.
- Before an intentional local LLM-backed run, announce a hard call or spend cap. Run only the selected local job or jobs; never start a worker or queue-drain command that could consume unrelated pending work.


## Stack notes

- UI is **Inertia 3 + React 19 + TypeScript + Tailwind v4** (Laravel React Starter Kit conventions). Routes go through controllers (`Inertia::render('PageName', $props)`); pages live in `resources/js/pages/`, components in `resources/js/components/`.
- `livewire/livewire` is reserved for Pulse internals; application UI uses Inertia.
- `openai-php/laravel` is wired as an **Azure OpenAI** client. `inertiajs/inertia-laravel` v3 speaks the **Inertia 3** protocol.
- App ships **two grounds**, switched by `data-theme` on `<html>`: the default follows `prefers-color-scheme`, and Settings can store an explicit light/dark choice. Use the ground-reactive semantic classes (`text-foreground`, `bg-card`, `text-leaf-ink`); fixed-dark surfaces such as sky cards pin their text explicitly. The complete fixed-vs-reactive token rules live in the `temari` skill and [docs/design-tokens.md](docs/design-tokens.md).
- Write UI copy, prompt strings, route paths, identifiers, enum values, and environment variable names in English; there is no i18n layer. Deliberate regression assertions retain the Indonesian phrases they prove absent: `maksimal 90 kata` / `maksimal 100 kata` in `NarratorsCoverageTest`. The badge-rename migration also retains old slugs as migration inputs.
- A second **`analytics`** DB connection (separate schema, same MySQL server) holds metering tables (e.g. `ai_token_usages`). Its migrations live in `database/migrations/analytics/` and run via `--database=analytics --path=...`; in tests it shares the default test DB (see [tests/TestCase.php](tests/TestCase.php)). Beyond `AI`, backend logic is split by domain under `app/Services/` (`AI`, `Run`, `Gamification`, `Strava`, `Geo`, `Weather`); see the `temari` skill for the full map.
- Don't add Inertia `prefetch`/`cacheFor` to links: prefetch runs the full controller on hover.
- Cache only primitives/arrays or classes listed in `config/cache.php` `serializable_classes` (anything else comes back as `__PHP_Incomplete_Class`); read cached numbers with `is_numeric`, since Redis returns strings.
- The CSP lives only in [docker/Caddyfile](docker/Caddyfile), so local runs never apply it; check its directives before shipping a new browser capability (blob URLs, workers, new origins, fonts).
- The prod Redis healthcheck in [compose.prod.yaml](compose.prod.yaml) stays a `SET` write-probe, never `ping`, which answers during AOF replay.

> **Design tokens, voice and tone, typography, the AI narrator pipeline, the 1:1 test convention, and the Sail toolchain live in the canonical `temari` skill at `.agents/skills/temari/`.** Activate it for UI, AI narration, or test work. Source-of-truth docs: [design-system/temari/MASTER.md](design-system/temari/MASTER.md) for screen composition, [docs/design-tokens.md](docs/design-tokens.md) for token values, [docs/voice-and-tone.md](docs/voice-and-tone.md) for copy.

## Knowledge base (`docs/`)

The human-facing knowledge base lives in `docs/` as `[[wikilinked]]` notes (a frontmatter template per [docs/_template.md](docs/_template.md), a MOC per section, [docs/DESIGN.md](docs/DESIGN.md) as the apex). A wikilink `[[x]]` resolves to the file `docs/**/x.md` (folder-form like `[[features/index]]` is a direct path) — open it with that glob; code links use root-relative paths.

- **Read before exploring unfamiliar territory.** Before starting non-trivial work on a page/feature/subsystem you haven't touched this session, `grep -rl` its name across `docs/features/` and `docs/architecture/` and read any match first — one cheap, targeted note beats re-deriving "why" from source or git history. Skip this for isolated, mechanical fixes (a CSS overflow, a narrow bug, a typo) where the doc wouldn't add context beyond what the code already shows.
- **Curated reference, not a diary.** Only features and *architecturally significant* decisions earn a note. No per-commit / work-log / changelog notes, that's git history + PR descriptions.
- **Cite code by `path:line`, never transcribe it.** A CI guard ([scripts/check-doc-citations.php](scripts/check-doc-citations.php)) fails the build if a doc cites a path that no longer exists.
- **Keep notes fresh in the same PR.** If a change makes an *existing* doc wrong, fix it alongside the code (don't write a new note per PR).
- **ADRs in `docs/decisions/` are immutable**: a changed decision gets a new dated note that supersedes it; a changed fact may get a dated one-line "superseded" banner, never a rewrite.

## Common commands

Everything runs in Docker via **Sail** (no host PHP/Node): run every container tool, npm included, through `./vendor/bin/sail` rather than `docker compose exec`, and don't run host python/perl over repo files. Stop at the first failure on the fast-feedback ladder; the full skill toolchain has the rest.

```bash
./vendor/bin/sail up -d                      # start the stack
./vendor/bin/sail pest --group=structure     # fast 1:1 + aggregate structural gate (run first)
./vendor/bin/sail bin pest --filter=Name     # a single test / file while iterating
./vendor/bin/sail npm run test               # frontend (Vitest); `test:coverage` for the 95% gate
./vendor/bin/sail npm run build              # build assets (`npm run dev` for HMR)
./vendor/bin/sail bin pint                    # format PHP (pre-commit also runs phpstan + eslint)
./vendor/bin/sail composer gate              # fast pre-push gate: tests for changed files only; CI runs the full suite
./vendor/bin/sail composer check:full        # reproduce CI locally, opt-in, slow
```

**Reading the gate.** Run it unpiped and read the final `GATE:` line. If you must capture the
output, `./vendor/bin/sail composer gate 2>&1 | tee <file>` and read `${PIPESTATUS[0]}` — never
`| tail`, which drops the failing step, and never `; echo EXIT=$?` after a pipe, which reports the
pipe's exit code rather than the gate's.

Running several agents at once, each in its own `git worktree`? See the `temari` skill's
"Parallel worktrees" section before starting a second Sail stack.

Merging `main` into a branch must be committed as `chore(<scope>): merge main into <branch>` —
the commit-msg hook rejects git's default merge message and carries no exemption for merges.
Every commit follows Conventional Commits, and the scope never contains a slash.

## LLM Integration

Briefing and analysis narration is LLM-backed via Azure OpenAI through openai-php/laravel ([AzureOpenAIClient](app/Services/AI/AzureOpenAIClient.php), [StructuredChatCaller](app/Services/AI/StructuredChatCaller.php), narrators under [app/Services/AI/Narrators/](app/Services/AI/Narrators/)). All narrator output flows through the [Analysis](app/Models/AI/Analysis.php) row model (status: pending / queued / processing / done / failed).

- **Failure and retry**: a job that exhausts `$tries` ([AnalyzeBaseJob](app/Jobs/AI/AnalyzeBaseJob.php)) lands in `failed_jobs`, marks its row `failed`, and the block shows "Try again" via [AnalysisStatus.tsx](resources/js/components/temari/AnalysisStatus.tsx); Horizon's failed-job tab retries it too.
- **Idempotency**: jobs early-exit on a row already `done` ([AnalyzeRowJob](app/Jobs/AI/AnalyzeRowJob.php), [AnalyzeGroupJob](app/Jobs/AI/AnalyzeGroupJob.php)), so racing retries never double-bill.
- **Paused vs failed**: a paused block (`AiEnabled` off, Azure unset, breaker tripped) stays `pending` and the hourly `ai:self-heal` ([SelfHealCommand](app/Console/Commands/AI/SelfHealCommand.php)) re-kicks it; a `failed` block gets bounded retries (`Analysis::MAX_SELF_HEAL_ATTEMPTS`), then dead-letters to `/devtools/narration`. Kickoffs in [routes/console.php](routes/console.php) never re-dispatch a failed block. The one exception: when a pause other than the app-wide cost ceiling lifts, an active athlete's block that failed during it gets one fresh attempt ([docs/decisions/failed-during-a-pause-retried-once-on-resume.md](docs/decisions/failed-during-a-pause-retried-once-on-resume.md)). See [docs/decisions/bounded-self-heal-and-dead-letter.md](docs/decisions/bounded-self-heal-and-dead-letter.md).
- **Cost ceilings**: past the per-athlete daily ceiling (`azure_openai.daily_cost_ceiling_per_user`), that athlete's `pending` blocks are filled by [RuleBasedNarrationFiller](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php) and manual triggers are refused ([docs/decisions/cost-ceiling-degrades-to-rule-based.md](docs/decisions/cost-ceiling-degrades-to-rule-based.md)); past the app-wide total (`azure_openai.daily_cost_ceiling_total`), every athlete degrades the same way, generation pauses and one maintainer alert fires ([docs/decisions/app-wide-ceiling-above-the-per-athlete-one.md](docs/decisions/app-wide-ceiling-above-the-per-athlete-one.md)). `failed` blocks stay `failed`, and there is no global emergency-mode chip.
- **Unconfigured env**: with `AZURE_OPENAI_URI` / `AZURE_OPENAI_API_KEY` empty, [AnalysisService](app/Services/AI/AnalysisService.php) skips dispatch and rows stay `pending`.
- **Demo**: [DemoSeedCommand](app/Console/Commands/DemoSeedCommand.php) fills every row rule-based under `AnalysisService::withoutDispatching()`, and demo "Reread" triggers are served rule-based too, so the demo spends no tokens ([docs/decisions/demo-triggers-served-rule-based.md](docs/decisions/demo-triggers-served-rule-based.md)). Every billing kickoff or scheduler excludes the demo user (`User::notDemo()`), with no exceptions ([docs/decisions/demo-user-billing-exclusion.md](docs/decisions/demo-user-billing-exclusion.md)).

## Environment toggles

- `DEMO_LOGIN_ENABLED` (default `true`): renders the "Try the demo" button on `/login` that signs in as the seeded demo user. Plumbed via [config/demo.php](config/demo.php) to Inertia shared `demoLoginEnabled`. Loaded in prod from the host `.env` via [compose.prod.yaml](compose.prod.yaml) `env_file:` ([ci.yml](.github/workflows/ci.yml) rolls the services, it does not inject these values).

## Secrets

- **Never read `.env` or other secret files directly** (`.env`, `*.pem`, `*.key`, `id_rsa`, `credentials.json`, `*.p12`, ...). Their values would leak into the session context, which persists. A local pre-tool hook additionally enforces this in one runtime; every agent must follow the rule directly. **Every `config:show`/`config:get` needs the user's explicit approval** - config reads resolve env values, so no key is auto-classified as "safe" to read. For a secret value, find the key NAME in `.env.example` and ask the user.

## Debugging

When a bug or error is reported, ground the investigation in real state before hypothesising. Server errors: `./vendor/bin/sail logs -f` or `storage/logs/laravel-*.log` (daily rotation). Data: `./vendor/bin/sail artisan tinker --execute '...'`, or `sail mysql`. Schema: `sail artisan db:show --counts` / `db:table <name>`. React/Inertia console errors: the browser devtools console, or the `browser-review` skill's scripts, which capture `console`/`pageerror` per page. When a local page 500s or a deferred prop never loads, check `migrate:status` for both migration sets first. Time app performance from inside the container; external timings include CDN and tunnel latency.

## Visual iteration

Batch several visual changes before reviewing one screenshot for the round. Activate the
`browser-review` skill for a viewport sweep or repeated image inspection; it owns the image-read,
cropping, and live-verification rules. Run the narrowest check that can fail before widening.
Confirm a localhost port is the local stack before interacting with it, since a tunnel can shadow
it. Verify every touched UI surface on both grounds and at a ≥1280px viewport, and list that matrix
in the PR.
