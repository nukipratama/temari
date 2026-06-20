# TemanLari

A self-hosted, Strava-connected personal running dashboard with a built-in companion (**Temari**) that narrates each run. UI in Bahasa Indonesia. Containerized end-to-end — Laravel Sail in dev, FrankenPHP behind a Cloudflare Tunnel in prod — and continuously deployed to a single self-hosted host on every merge to `main`.

> **Status**: Live in prod. Strava OAuth + activity sync, briefing/verdict narration (Azure OpenAI with rule-based fallback), training-load (CTL/ATL/Form), per-run RunCards, weekly snapshots, and the Temari mascot are all shipping. Daybreak palette (pre-dawn peach `horizon` + cream paper + navy `sky`), intentionally far from Strava orange. Light-mode only.

## What it is

TemanLari ("running buddy") turns your Strava runs into something you look forward to opening. Each run is ingested and scored with proper running metrics — pace, splits, HR zones, training load — then dealt as a collectible **kartu** with a rarity and a vibe, and narrated by **Temari**, a mascot companion who reads your day back to you in a warm, Indonesian-first voice.

It is deliberately **not** a Strava clone. The run-tracker core is correct and honest, but the point is the companion layer on top: it's built for the solo runner who finds raw dashboards cold and wants their training to feel like a story. Single-tenant and self-hosted by design.

### What you get

- **Daily briefing** — Temari's read on today: greeting, vitals, a session suggestion, the last run. See [docs/features/dashboard.md](docs/features/dashboard.md).
- **Run cards & collection** — every run becomes a kartu with a rarity, vibe, badges, and special moves. See [docs/features/cards-collection.md](docs/features/cards-collection.md).
- **Run detail** — four-lens breakdown, route map, splits, HR zones, AI insights per run. See [docs/features/run-detail.md](docs/features/run-detail.md).
- **Training load & records** — CTL/ATL/Form, personal records, progression. See [docs/features/records.md](docs/features/records.md).
- **Recaps** — weekly, monthly, and persona narratives in Temari's voice. See [docs/features/recaps.md](docs/features/recaps.md).
- **Targets & accessories** — goals and unlockable mascot accessories. See [docs/features/targets-accessories.md](docs/features/targets-accessories.md).

Full feature map: [docs/features/index.md](docs/features/index.md).

## Stack

- **Backend**: Laravel 13 · PHP 8.4 (FrankenPHP + Octane in prod, Sail's PHP image in dev) · Larastan L8 · Pint · Rector
- **Frontend**: Inertia 2 + React 19 + TypeScript · Tailwind v4 (`@tailwindcss/vite`) · Framer Motion · Vitest
- **Data**: MySQL 8.4 + Redis (separate dev / test / prod stacks for parity)
- **Async**: Horizon (queues) · Scheduler
- **Observability**: Telescope (dev) · Pulse (perf)
- **LLM**: Azure OpenAI via `openai-php/laravel` for briefing/verdict narration; when credentials are unset, narration silently falls back to deterministic rule-based content. Per-block `AnalysisStatus` (pending / failed + a "Coba lagi" retry button) is the source of truth — there is no global "mode darurat" chip
- **Tests**: Pest 4 (95% line coverage gate) · Vitest (95% lines + functions gate)
- **AI dev**: Laravel Boost — `CLAUDE.md` + `.claude/skills/*` for AI-paired work; `laravel/claude-code` plugin enabled in `.claude/settings.json`

## Quick start

```bash
# 1. Sail file ownership (matches host UID/GID)
echo "WWWUSER=$(id -u)"  >> .env
echo "WWWGROUP=$(id -g)" >> .env

# 2. Bring up the dev stack
./vendor/bin/sail up -d

# 3. App init
./vendor/bin/sail composer install
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

App at **http://localhost:7001**.

## Documentation

This README covers how to *operate* the app. For how it's *built and why*, the knowledge base lives in [docs/](docs/) as cross-linked Markdown notes — start at [docs/DESIGN.md](docs/DESIGN.md) (the apex) and follow the per-section Maps of Content: [Architecture](docs/architecture/index.md) · [Decisions (ADRs)](docs/decisions/index.md) · [Features](docs/features/index.md).

## Development

Day-to-day commands (all inside Sail):

```bash
./vendor/bin/sail composer run dev   # Vite + queue listener + log watcher
./vendor/bin/sail composer check     # one-shot gate: pint + phpstan + rector + pest + tsc + vitest
./vendor/bin/sail bin pest           # PHP tests
./vendor/bin/sail bin pest --parallel    # parallel (works locally; test user is granted per-process DB rights)
./vendor/bin/sail npm run test       # FE tests (Vitest)
./vendor/bin/sail npm run test:coverage  # FE tests with 95% line+function gate
./vendor/bin/sail bin pint           # format
./vendor/bin/sail bin phpstan analyse    # static analysis
./vendor/bin/sail bin rector --dry-run   # refactor suggestions
```

Frontend pages live in [resources/js/pages/](resources/js/pages/), components in [resources/js/components/](resources/js/components/). Routes still go through Laravel controllers (`Inertia::render('PageName', $props)`).

### Ports

| Service        | Host port | Container port |
|---------------:|:---------:|:--------------:|
| App (Nginx)    | 7001      | 80             |
| Vite HMR       | 7002      | 5173           |
| MySQL (dev)    | 7003      | 3306           |
| Redis (dev)    | 7004      | 6379           |

The test stack (`mysql_test`, `redis_test`) runs on the compose network only — no host port forwards.

## Testing

**PHP** — Pest 4 against a dedicated test stack: `mysql_test` (tmpfs-backed, ephemeral) and `redis_test`, configured in `phpunit.xml`. Same image versions as prod for parity. `RefreshDatabase` resets schema per test class. 1:1 class↔test convention — every class file has its own test (mocking siblings is encouraged).

**Frontend** — Vitest with jsdom against React 19 + Inertia components. Same 1:1 convention. Gates: 95% lines + 95% functions ([vitest.config.ts](vitest.config.ts)). Branches relaxed because hitting every `?? null` fallback in defensive code is contortionist, not signal.

CI uses GitHub Actions service containers (`mysql:8.4` + `redis:8-alpine`) for the PHP suite — every workflow run gets a fresh DB. FE suite is pure-node, no services.

`TelescopeServiceProvider` and `HorizonServiceProvider` are excluded from coverage in `phpunit.xml` — both are framework-wiring with closures that only fire under runtime conditions and aren't meaningfully testable in isolation.

## Quality gates

| Where           | What runs                                                              |
|:----------------|:-----------------------------------------------------------------------|
| pre-commit      | `pint` (auto-format staged PHP) + `phpstan` (whole `app/`)             |
| commit-msg      | Conventional Commits format check                                      |
| pre-push        | Block direct pushes to `main` (force or not). Use feature branch + PR  |
| CI — `lint`     | `pint --test`, `phpstan`, `rector --dry-run` (no DB, fast)             |
| CI — `pest`     | `pest --coverage --min=95` against mysql:8.4 + redis:8-alpine services |
| CI — `vitest`   | `npm run test:coverage` — 95% lines + functions, jsdom only            |
| CI — `deploy`   | On push to `main`: build prod image, migrate, roll containers, recycle Horizon, healthcheck `/up` |

## Branch workflow

Direct pushes to `main` are blocked by `.githooks/pre-push`. To land a change:

```bash
git switch -c feat/your-change
# ... edit + commit ...
git push -u origin feat/your-change
gh pr create --fill           # or open in the GitHub UI
# CI runs; merge once green via the GitHub UI
```

## Deployment

- **Target**: a single self-hosted Docker host, behind an existing Cloudflare Tunnel.
- **Runtime**: FrankenPHP — Caddy + PHP in one container, listening on `:7001`.
- **Trigger**: every push to `main`.

### Architecture

```
Internet → Cloudflare edge (TLS terminates) → Cloudflare Tunnel
                                                   ↓
                  existing host-level cloudflared (out of scope)
                                                   ↓ http://127.0.0.1:7001
                                       ┌──────────────────────────┐
                                       │  app (FrankenPHP)        │
                                       │  horizon (queue worker)  │
                                       │  scheduler               │
                                       │  mysql (persistent vol)  │
                                       │  redis (persistent vol)  │
                                       └──────────────────────────┘
```

`app` is the only service that exposes a host port (loopback-only `127.0.0.1:7001`). `mysql` and `redis` stay on the compose network with persistent named volumes.

Defined in [compose.prod.yaml](compose.prod.yaml) + [Dockerfile](Dockerfile) + [docker/Caddyfile](docker/Caddyfile).

### How a deploy works

1. PR merges to `main`.
2. The `deploy` job in [.github/workflows/ci.yml](.github/workflows/ci.yml) waits for `lint` + `pest` to pass.
3. The job runs on a containerized self-hosted runner registered for this repo, connecting outbound-only (no inbound port).
4. On the host, the job: tags the current `:latest` as `:previous`, builds a new image, tags it with the git SHA, runs `migrate --force`, rolls `app`/`horizon`/`scheduler`, runs `artisan optimize`, recycles Horizon workers via `horizon:terminate`, healthchecks `/up`, and prunes images older than 7 days.

### Setup (one-time, on the host)

Prod secrets and per-host config live in `/opt/teman-lari/.env` on the host (root-owned, `640`, readable by the runner) — not committed, and nothing flows through GitHub Actions secrets. Compose loads the file via `env_file:` on each service.

Create `/opt/teman-lari/.env` with:

| Key                   | Value                                                                                                |
|:----------------------|:-----------------------------------------------------------------------------------------------------|
| `APP_KEY`             | `base64:...` (generate: `php -r 'echo "base64:".base64_encode(random_bytes(32))."\n";'`)             |
| `APP_URL`             | The Cloudflare-fronted public URL (`https://<your-domain>`)                                          |
| `DB_DATABASE`         | e.g. `teman_lari`                                                                                    |
| `DB_USERNAME`         | e.g. `teman_lari`                                                                                    |
| `DB_PASSWORD`         | strong random                                                                                        |
| `MYSQL_ROOT_PASSWORD` | strong random — used on first mysql init only; cannot be changed after the volume exists            |
| `STRAVA_CLIENT_ID`    | from your Strava developer app                                                                       |
| `STRAVA_CLIENT_SECRET`| from your Strava developer app                                                                       |
| `AZURE_OPENAI_URI`    | Optional. Full Azure OpenAI deployment URL (incl. api-version). Empty = rule-based narration silently |
| `AZURE_OPENAI_API_KEY`| Optional. Pairs with `AZURE_OPENAI_URI`. Empty = rule-based silently. Job failures mark that Analysis block `failed` with a per-block retry button |
| `DEMO_LOGIN_ENABLED`  | Optional. `true` renders the "Coba versi demo" button on `/login`. Default `false`                  |
| `ONBOARDING_FORCE_SHOW` | Optional. `true` re-renders the dashboard first-run tooltip on every mount (QA / demos). Default `false` |

The Strava redirect URL is derived from `APP_URL` + the `auth.strava.callback` route — no separate secret. Make sure your Strava app's "Authorization Callback Domain" matches the host portion of `APP_URL`.

In **Cloudflare Zero Trust → tunnel → Public Hostnames**, route `<your-domain>` to `http://localhost:7001`.

After both are in place, merging the PR triggers the first deploy: mysql initializes its volume with `MYSQL_ROOT_PASSWORD`, the app image builds, migrations run, containers come up, healthcheck passes. Subsequent deploys are fully automatic.

### Rollback

Every successful deploy leaves two extra image tags on the host:
- `teman-lari/app:previous` — the image that was `:latest` before this deploy.
- `teman-lari/app:<git-sha>` — addressable artifact for any prior commit.

Non-running images older than 7 days are pruned automatically.

**Roll back the most recent deploy** (most common case):
```bash
docker tag teman-lari/app:previous teman-lari/app:latest
docker compose -f compose.prod.yaml up -d --no-deps app horizon scheduler
docker compose -f compose.prod.yaml exec -T app php artisan horizon:terminate
```

**Roll back to a specific commit** (within the 7-day retention window):
```bash
docker image ls teman-lari/app                      # find the SHA you want
docker tag teman-lari/app:<sha> teman-lari/app:latest
# ... up + horizon:terminate as above ...
```

For both: env vars (`APP_KEY`, DB creds, etc.) must be in your shell before running compose — easiest path is to re-run the relevant GitHub Actions deploy job rather than fight compose env locally. `compose.prod.yaml`'s `${VAR:?required}` markers tell you what's missing if anything's unset.
