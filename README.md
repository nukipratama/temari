# Teman Lari

Personal Laravel app, vibe-coded end-to-end. Fully containerized via Laravel Sail — no PHP/Composer/Node on the host.

## Stack

- Laravel 13.5.0 + Blade + Tailwind (via `@tailwindcss/vite`)
- Laravel Boost (CLAUDE.md + `.claude/skills/*` for AI agents) and the official `laravel/claude-code` Claude Code plugin (enabled in `.claude/settings.json`)
- PHP 8.4 (pinned in compose.yaml)
- MySQL 8.4 (dev + isolated test container)
- Redis (dev + isolated test container)
- Mailpit (mail catcher, UI at port 7006)
- Pest 4 + Larastan level 8 + Pint (PSR-12) + Rector + Infection
- Telescope (local debug), Horizon (queue dashboard), Pulse (perf dashboard)

## First-time setup

```bash
# 1. Set Sail file ownership env vars (match your host UID/GID)
echo "WWWUSER=$(id -u)"  >> .env
echo "WWWGROUP=$(id -g)" >> .env

# 2. Bring up the Sail stack (first run pulls images, ~2-5 min)
./vendor/bin/sail up -d

# 3. App setup (composer install auto-wires git hooks via post-install-cmd)
./vendor/bin/sail composer install
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install
./vendor/bin/sail npm run build

# 4. (Optional) Install Boost agent guidance (interactive)
./vendor/bin/sail artisan boost:install
```

App is at **http://localhost:7001**.

## Day-to-day

```bash
./vendor/bin/sail composer run dev   # Vite HMR + queue listener + log watcher
./vendor/bin/sail pest               # tests
./vendor/bin/sail pint               # auto-format
./vendor/bin/sail phpstan analyse    # static analysis
./vendor/bin/sail rector --dry-run   # refactor suggestions
```

## Ports (host → container)

| Service        | Host port | Internal |
|---------------:|:---------:|:--------:|
| App (Nginx)    | 7001      | 80       |
| Vite HMR       | 7002      | 5173     |
| MySQL (dev)    | 7003      | 3306     |
| Redis (dev)    | 7004      | 6379     |
| Mailpit SMTP   | 7005      | 1025     |
| Mailpit UI     | 7006      | 8025     |

The test stack (`mysql_test`, `redis_test`) runs without host port forwards — tests reach it over the compose network.

## Test stack

Pest tests run against `mysql_test` (tmpfs-backed, ephemeral) and `redis_test` containers, configured in `phpunit.xml`. Same image versions as prod for parity. Each `sail up` gives a fresh database; `RefreshDatabase` trait handles per-test reset.

CI uses GitHub Actions service containers (mysql:8.4 + redis:alpine) — every workflow run gets a fresh DB.

## Quality gates

| Where        | Runs                                                                  |
|:-------------|:----------------------------------------------------------------------|
| pre-commit   | `pint` (auto-format staged PHP) + `phpstan` (whole `app/`)            |
| commit-msg   | Conventional Commits format check                                     |
| post-commit  | Append entry to `CHANGELOG.md` and amend into HEAD                    |
| pre-push     | Block direct pushes to `main` on `origin` (force or not). Use feature branch + PR + GitHub UI merge |
| CI — `lint` job   | `pint --test`, `phpstan`, `rector --dry-run` (no DB, fast)         |
| CI — `pest` job   | `pest --coverage --min=100` (boots Laravel against `mysql:8.4` + `redis:alpine` service containers) |
| CI — `deploy` job | On push to `main`, after `lint`+`pest` green: builds the prod image on the homelab runner, migrates, rolls `app`/`horizon`/`scheduler`, recycles Horizon workers, healthchecks `/up` |

100% line coverage gate is enforced in CI (`pest --coverage --min=100`). `TelescopeServiceProvider` and `HorizonServiceProvider` are excluded in `phpunit.xml` — both are framework-wiring with closures that only fire under runtime conditions and aren't meaningfully testable in isolation.

## Branch workflow

Direct pushes to `main` are blocked client-side by `.githooks/pre-push`. To land a change:

```bash
git switch -c feat/your-change
# ... edit + commit ...
git push -u origin feat/your-change
gh pr create --fill           # or open in the GitHub UI
# CI runs; merge once green via the GitHub UI
```

## Deploy

Target: **homelab**, Docker, behind an existing Cloudflare Tunnel. CI and prod share the same box. The runtime is **FrankenPHP** (one container serves the app on `:7001` — no nginx/fpm split, no LE certs since Cloudflare terminates TLS).

### Stack

- `app` — FrankenPHP, listens on `127.0.0.1:7001` (loopback only — host-level cloudflared reaches it).
- `horizon` — same image, runs `php artisan horizon`.
- `scheduler` — same image, runs `php artisan schedule:work`.
- `mysql` — `mysql:8.4`, network-only, persistent volume.
- `redis` — `redis:7-alpine`, network-only, persistent volume.

Defined in [compose.prod.yaml](compose.prod.yaml) + [Dockerfile](Dockerfile) + [docker/Caddyfile](docker/Caddyfile).

### How a deploy happens

1. Merge a PR to `main` on GitHub.
2. The GitHub Actions `deploy` job (in [.github/workflows/ci.yml](.github/workflows/ci.yml)) waits for `lint` + `pest` to pass.
3. The job runs on the self-hosted runner registered for this repo (label `homelab`). The runner is provisioned separately on the homelab box as a containerized wrapper around the official `actions/runner` binary, in `--ephemeral` mode (one job per process; `restart: unless-stopped` cycles it). `network_mode: host` so workflow `services:` blocks resolve via `127.0.0.1` like on `ubuntu-latest`. Sudo lives inside the runner container only — no `NOPASSWD` grant on the host user. The runner pulls work from GitHub over an outbound long-poll — no inbound port needed.
4. On the homelab box, the job builds the image, runs `migrate --force` against the new image, rolls `app`/`horizon`/`scheduler`, recycles Horizon workers, and curls `/up` to confirm.

### Setup (one-time, all in GitHub UI)

`compose.prod.yaml` reads every prod-side secret from environment variables (no on-host `.env` file). The `deploy` job pulls them from repo Secrets and passes them through to compose, which substitutes `${VAR}` at parse time. Nothing sensitive is ever written to disk on the homelab.

**Repo Settings → Secrets and variables → Actions → Secrets → New repository secret**, add:

| Secret | Value | Notes |
|---|---|---|
| `APP_KEY` | `base64:<32-byte-base64>` | Generate: `php -r 'echo "base64:".base64_encode(random_bytes(32))."\n";'` |
| `APP_URL` | `https://<your-domain>` | The Cloudflare-fronted public URL |
| `DB_DATABASE` | `teman_lari` | Both Laravel and the mysql init use this |
| `DB_USERNAME` | `teman_lari` | Same |
| `DB_PASSWORD` | strong random | Same |
| `MYSQL_ROOT_PASSWORD` | strong random | Used only by mysql container init; can't be changed after first boot |
| `STRAVA_CLIENT_ID` | from Strava app | |
| `STRAVA_CLIENT_SECRET` | from Strava app | The redirect URL is derived from `APP_URL` + the `auth.strava.callback` route — no separate secret needed |

**Cloudflare Zero Trust → your existing tunnel → Public Hostnames → Add**: route the domain to `http://localhost:7001`.

**Repo Settings → Branches → Add rule for `main`**: require `lint` + `pest` status checks, "Do not allow bypassing".

After all of the above, merging the PR triggers the first deploy: mysql initializes its volume with `MYSQL_ROOT_PASSWORD`, app builds, migrate runs, containers come up, healthcheck passes. Subsequent deploys are fully automatic — every push to `main` rolls a new image.

### Manual rollback

Every successful deploy leaves the prior image tagged `teman-lari/app:previous` and the new image tagged with its git SHA (`teman-lari/app:<sha>`). All non-running images older than 7 days get pruned.

**Roll back the most recent deploy** (most common case):
```bash
docker tag teman-lari/app:previous teman-lari/app:latest
docker compose -f compose.prod.yaml up -d --no-deps app horizon scheduler
docker compose -f compose.prod.yaml exec -T app php artisan horizon:terminate
```

**Roll back to a specific commit** (within the 7-day retention window):
```bash
docker image ls teman-lari/app                            # find the SHA you want
docker tag teman-lari/app:<sha> teman-lari/app:latest
docker compose -f compose.prod.yaml up -d --no-deps app horizon scheduler
docker compose -f compose.prod.yaml exec -T app php artisan horizon:terminate
```

For both: env vars (`APP_KEY`, DB creds, etc.) must be in your shell before running compose — easiest path is to re-run the relevant GitHub Actions deploy job rather than fighting compose env locally. `compose.prod.yaml`'s `${VAR:?required}` markers will tell you what's missing if anything's unset.
