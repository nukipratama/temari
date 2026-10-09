# CLAUDE.md

## Agent workflow

- Multi-slice work ships as a GitHub stack (`gh stack`): one PR per layer, merged by the owner. Read the `temari` skill's [stacked-prs reference](.claude/skills/temari/references/stacked-prs.md) before starting one.
- Worktrees (`EnterWorktree`/`ExitWorktree`, `scripts/worktree create|adopt|remove|prune|info`): read the `temari` skill's [parallel-worktrees reference](.claude/skills/temari/references/parallel-worktrees.md) before creating or removing one, or starting a second Sail stack. Read a checkout's slot and ports with `scripts/worktree info [path]`, never from `.env`.
- Never `artisan tinker <file>`, use `--execute`. Stop tests, builds, and servers when their task ends.
- Work items, agent briefs and decisions live in GitHub issues and the [kanban board](https://github.com/users/nukipratama/projects/1), not in local files: find them with `gh issue list --label wave:*` and `gh issue view <n>`. Each settled decision is its own issue labelled `decision` (`gh issue list --label decision --state all`); an owner call still open is labelled `needs-decision`. A card moves Ready → In progress on dispatch → In review when its PR opens → Done on merge, and every PR carries `Closes #<n>`. `.planning/` is gitignored scratch space only.
- Long or multi-agent runs track progress with one writer per level. The main session owns the milestone checklist (checkboxes in the parent issue), ticks an item only after verifying the agent's report against real state, and files agents' new findings as backlog issues. Each agent keeps its own `.planning/progress.md` and never edits another agent's.
- For plan/coaching policy calls (redistribution, clamps, grading), decide from established coaching practice, record the rationale in the PR or ADR, and build; ask the owner only about product, UX or infra trade-offs.
- Parallel briefs assign each agent its route paths and names, with no placeholder routes; the later PR checks `routes/` for duplicate paths after merging `main`.
- Polish runs last: land every scope change before the pass, and a commit made after it needs another pass before the PR opens.
- `scripts/pr-status [n…]` prints one line per open PR: checks, merge state, auto-merge and the closing issue's board column. Never poll CI with `sleep` loops or `gh … --watch`.
- `gh project item-list` fetches the whole board, about 500 of the 5,000 GraphQL points an hour that every agent on the account shares. Look up one card through its issue's `projectItems` instead, as `scripts/pr-status` does.
- Every PR is a reviewer handoff: read the `temari` skill's [pr-handoff reference](.claude/skills/temari/references/pr-handoff.md) before opening or editing one. The repository is public: keep issues and PRs free of secrets, and describe athlete ids, emails, hostnames and per-athlete costs rather than pasting them.
- An unmeasured cause is a hypothesis: say so, and measure it with the authoritative source before shipping a fix or rule for it.
- Run long commands with Bash `timeout: 600000` instead of backgrounding them.
- Bind each opened PR to the desktop app's CI monitor (`ccd_pr` `bind_pr`) and wait for its events instead of polling.

## External actions

- Read production runtime state from the live container (`artisan tinker --execute`, with per-use approval), never from the repo `.env` or config files.
- Before an intentional local LLM-backed run, announce a hard call or spend cap. Run only the selected local job or jobs; never start a worker or queue-drain command that could consume unrelated pending work.

## Stack notes

- UI is **Inertia 3 + React 19 + TypeScript + Tailwind v4** (Laravel React Starter Kit conventions). Routes go through controllers (`Inertia::render('PageName', $props)`); pages live in `resources/js/pages/`, components in `resources/js/components/`. `livewire/livewire` is reserved for Pulse internals.
- `openai-php/laravel` is wired as an **Azure OpenAI** client. `inertiajs/inertia-laravel` v3 speaks the **Inertia 3** protocol.
- App ships **two grounds**, switched by `data-theme` on `<html>`: the default follows `prefers-color-scheme`, and Settings can store an explicit light/dark choice. Use the ground-reactive semantic classes (`text-foreground`, `bg-card`, `text-leaf-ink`); fixed-dark surfaces such as sky cards pin their text explicitly. The fixed-vs-reactive token rules live in the `temari` skill and [docs/design-tokens.md](docs/design-tokens.md).
- Write UI copy, prompt strings, route paths, identifiers, enum values, and environment variable names in English; there is no i18n layer. Deliberate regression assertions retain the Indonesian phrases they prove absent: `maksimal 90 kata` / `maksimal 100 kata` in `NarratorsCoverageTest`. The badge-rename migration also retains old slugs as migration inputs.
- The **`analytics`** DB connection's migrations run via `--database=analytics --path=...`; in tests it shares the default test DB (see [tests/TestCase.php](tests/TestCase.php)).
- Don't add Inertia `prefetch`/`cacheFor` to links: prefetch runs the full controller on hover.
- Cache only primitives/arrays or classes listed in `config/cache.php` `serializable_classes` (anything else comes back as `__PHP_Incomplete_Class`); read cached numbers with `is_numeric`, since Redis returns strings.
- The CSP lives only in [docker/Caddyfile](docker/Caddyfile), so local runs never apply it; check its directives before shipping a new browser capability (blob URLs, workers, new origins, fonts).
- The prod Redis healthcheck in [compose.prod.yaml](compose.prod.yaml) stays a `SET` write-probe, never `ping`, which answers during AOF replay.

> **Design tokens, voice and tone, typography, the AI narrator pipeline, the 1:1 test convention, and the Sail toolchain live in the `temari` skill at `.claude/skills/temari/`.** Activate it for UI, AI narration, or test work. Source-of-truth docs: [design-system/temari/MASTER.md](design-system/temari/MASTER.md) for screen composition, [docs/design-tokens.md](docs/design-tokens.md) for token values, [docs/voice-and-tone.md](docs/voice-and-tone.md) for copy.

## Knowledge base (`docs/`)

`docs/` holds `[[wikilinked]]` notes (template [docs/_template.md](docs/_template.md), a MOC per section, [docs/DESIGN.md](docs/DESIGN.md) as the apex). `[[x]]` resolves to `docs/**/x.md`; folder-form `[[features/index]]` is a direct path. Code links use root-relative paths.

- Before non-trivial work on a page/feature/subsystem you haven't touched this session, `grep -rl` its name across `docs/features/` and `docs/architecture/` and read any match. Skip this for isolated, mechanical fixes.
- Only features and *architecturally significant* decisions earn a note. No per-commit, work-log or changelog notes.
- Cite code by path plus a named symbol (no `#L` anchors in living notes), never transcribe it; [scripts/check-doc-citations.php](scripts/check-doc-citations.php) fails CI on a path that no longer exists.
- Fix an *existing* doc a change makes wrong in the same PR; don't write a new note per PR.
- ADRs in `docs/decisions/` are immutable: a changed decision gets a new dated note that supersedes it; a changed fact may get a dated one-line "superseded" banner, never a rewrite.

## Common commands

Everything runs in Docker via **Sail** (no host PHP/Node): run every container tool, npm included, through `./vendor/bin/sail` rather than `docker compose exec`; only one agent at a time runs Sail in a given checkout. Run `./vendor/bin/sail composer gate` unpiped before pushing; the command ladder (narrowest test first) and how to read the gate are in the `temari` skill's [toolchain reference](.claude/skills/temari/references/toolchain.md). Prefer Edit for a targeted change; host perl/sed/python are fine for a multi-file mechanical rewrite, followed by `git diff --stat` to confirm only the expected files changed.

Merging `main` into a branch must be committed as `chore(<scope>): merge main into <branch>`; the commit-msg hook rejects git's default merge message and has no merge exemption.

## LLM Integration

Narration goes through the [Analysis](app/Models/AI/Analysis.php) row model. Its failure, retry, idempotency, pause, cost-ceiling, unconfigured-env and demo rules are in the `temari` skill's [narration reference](.claude/skills/temari/references/narration.md). Every billing kickoff or scheduler excludes the demo user (`User::notDemo()`), with no exceptions.

## Environment toggles

- `DEMO_LOGIN_ENABLED` (default `true`): renders the "Try the demo" button on `/login` that signs in as the seeded demo user. Plumbed via [config/demo.php](config/demo.php) to Inertia shared `demoLoginEnabled`. Loaded in prod from the host `.env` via [compose.prod.yaml](compose.prod.yaml) `env_file:` ([deploy.yml](.github/workflows/deploy.yml) rolls the services, it does not inject these values).

## Secrets

- **Never read `.env` or other secret files directly** (`.env`, `*.pem`, `*.key`, `id_rsa`, `credentials.json`, `*.p12`, ...). Their values would leak into the session context, which persists. A global pre-tool hook outside this repo also enforces it; follow the rule directly regardless. **Every `config:show`/`config:get` needs the user's explicit approval** - config reads resolve env values, so no key is auto-classified as "safe" to read. For a secret value, find the key NAME in `.env.example` and ask the user.

## Debugging

When a bug or error is reported, ground the investigation in real state before hypothesising. Server errors: `./vendor/bin/sail logs -f` or `storage/logs/laravel-*.log` (daily rotation). Data: `./vendor/bin/sail artisan tinker --execute '...'`, or `sail mysql`. Schema: `sail artisan db:show --counts` / `db:table <name>`. React/Inertia console errors: the browser devtools console, or the `browser-review` skill's scripts, which capture `console`/`pageerror` per page. When a local page 500s or a deferred prop never loads, check `migrate:status` for both migration sets first. Time app performance from inside the container; external timings include CDN and tunnel latency.

## Visual iteration

Verify every touched UI surface on both grounds and at a ≥1280px viewport, and list that matrix in the PR. Activate the `browser-review` skill before reviewing any screenshot: it owns the image budget, the localhost port check and the sweep rules.
