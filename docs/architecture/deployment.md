---
title: Deployment & runtime
description: Multi-stage FrankenPHP/Octane image built on a hosted runner and pushed to GHCR, the loopback-only prod compose stack behind a Cloudflare Tunnel, Redis DB partitioning, and the GitHub Actions pull/migrate/roll/rollback flow on a single homelab host
tags: [architecture, infra]
status: living
reviewed: 2026-10-06
code_refs:
  - Dockerfile
  - compose.prod.yaml
  - docker/Caddyfile
  - .github/workflows/ci.yml
  - .github/workflows/deploy.yml
  - .github/workflows/nightly-audit.yml
  - .github/workflows/nightly-backup.yml
  - .github/workflows/restore-dry-run.yml
  - .github/workflows/maintainer-alert.yml
  - .github/workflows/backup-watchdog.yml
  - scripts/restore-db.sh
  - scripts/deploy/ensure-backup-user.sh
  - scripts/deploy/backup-password.sh
  - scripts/deploy/replay-binlogs.sh
  - scripts/deploy/verify-and-prune-backup.sh
  - scripts/deploy/select-images-to-prune.sh
  - scripts/deploy/count-rows-query.sh
  - scripts/deploy/check-restore-manifest.sh
  - scripts/check-migration-safety.php
  - deploy/restore-dry-run-compose.yml
  - config/octane.php
  - config/database.php
  - routes/console.php
  - README.md
---

# Deployment & runtime

How Temari is built into an image, run as a compose stack, and continuously deployed to **one self-hosted homelab host** on every push to `main`. The image is *built* on a GitHub-hosted runner and pulled from GHCR; only the run stack lives on the homelab. The host sits behind an existing Cloudflare Tunnel; nothing in this repo provisions the tunnel itself. Start here before touching the [Dockerfile](Dockerfile), [compose.prod.yaml](compose.prod.yaml), the `build` job in [.github/workflows/ci.yml](.github/workflows/ci.yml) or the deploy in [.github/workflows/deploy.yml](.github/workflows/deploy.yml).

## The image (multi-stage)

[Dockerfile](Dockerfile) is one file with five stages, all pinned by digest so a floating tag can't drift the runtime out from under us:

- **`dev`** — local-only FrankenPHP target (traditional mode, no Octane worker). Bakes PHP extensions + the pinned Node toolchain + `docker/Caddyfile.dev`; source is volume-mounted at runtime.
- **`vendor`** — `composer install --no-dev`, then `dump-autoload --classmap-authoritative`. The `package:discover` hook is **deferred** out of this stage (the `composer:2` image has no redis ext, so a provider boot would crash).
- **`assets`** — `npm ci` + `npm run build` (Vite + `@tailwindcss/vite`), pulling `vendor/` in because some packages publish CSS/JS.
- **runtime** (final, unnamed) — fresh FrankenPHP, copies `vendor/` and `public/build`, installs the runtime extensions (`pdo_mysql`, `redis`, `intl`, `bcmath`, `opcache`, `pcntl`), then runs the deferred `package:discover` with `CACHE_STORE=array`. The image loads no `php.ini`; [docker/php.ini](docker/php.ini) is copied in as `zz-app.ini` and is the only app-level PHP config: opcache with JIT off, `expose_php` off, assertions compiled out, and an opcache file cache in `/tmp/opcache` so short-lived artisan processes (scheduler runs, healthchecks) reuse compiled scripts.

[.dockerignore](.dockerignore) is an allow-list: it starts with `*` and re-includes only what the runtime or a build stage reads, so a new top-level tree stays out of the image by default. The `vendor` stage copies the whole filtered context, so the build inputs (`package*.json`, `vite.config.ts`, `tsconfig.json`, the `docker/` configs, `resources/js`) also ship, unused at runtime. `build-prod-image` loads the image and fails when `/var/www/html` holds any top-level entry other than its `EXPECTED_ENTRIES` list. It does so only on a PR whose `changes` job sets `image`, meaning the PR touched `Dockerfile`, `.dockerignore`, `docker/`, the composer or package manifests, or `ci.yml`: nothing else can add or remove a top-level entry, and on a fully cached build loading the image costs about 2.5 minutes. Pushes to `main` skip it, because the PR already checked it. [ProdImageAllowListTest](tests/Unit/Architecture/ProdImageAllowListTest.php) keeps that list, the allow-list and the Dockerfile's context `COPY`s in step.

`config:cache` is deliberately **not** baked into the image — build time has no `.env`, so `env()` would freeze PHP defaults (e.g. `DB_CONNECTION` → `sqlite` in [config/database.php](config/database.php)) into the cache. Caching happens at deploy time instead, inside the running container. See `package:discover` + the `Optimize caches` step ([.github/workflows/deploy.yml](.github/workflows/deploy.yml)) and [[defer-config-cache]].

## Runtime: FrankenPHP + Octane

The runtime stage serves on **`:7001`** plain HTTP — TLS terminates at Cloudflare, so `auto_https off` in [docker/Caddyfile](docker/Caddyfile). The live worker loop is the Caddyfile **`frankenphp { worker { ... } }`** directive (FrankenPHP's `frankenphp-worker.php`), **not** `octane:start`. So the recycle/sizing knobs are `num 2` and `env MAX_REQUESTS 2000` in the Caddyfile, while `OCTANE_MAX_REQUESTS` / `FRANKENPHP_NUM_WORKERS` in [compose.prod.yaml](compose.prod.yaml) are inert (kept only in case we ever switch to `octane:start`). `FRANKENPHP_NUM_THREADS=4` is a concrete count in the [Dockerfile](Dockerfile), not `auto`, because docker-out-of-docker means `auto` would size off the host's full core count and over-subscribe the 2-CPU-capped container. [config/octane.php](config/octane.php) still supplies `server => frankenphp` and the per-request flush listeners. FrankenPHP **must** load `/etc/frankenphp/Caddyfile` — any other path silently falls back to the image default (no worker directive, no cache headers).

### Caddy front (in-container)

[docker/Caddyfile](docker/Caddyfile) handles static caching (`/build/*` immutable, favicons 7d) and sets **no** caching on dynamic responses; the only HTTP caching on an app page is the app-layer conditional GET that `/activities/{activity}` alone opts into, described in [[frontend-architecture]] ("Conditional GET on run detail"). The ops dashboards (`/devtools/horizon`, `/devtools/pulse`, `/devtools/narration`) and the Livewire update endpoint they POST through are authorized inside Laravel by HTTP Basic Auth against a shared devtools password ([[narration-devtools]] Access section) whenever `APP_ENV` is `production`, independent of any Strava session; Cloudflare Access fronts the edge as well. `trusted_proxies static private_ranges` is set, and the app narrows both *which peers* (loopback + RFC1918 ranges) and *which headers* (`X-Forwarded-For`/`Proto`/`Port` only, `Host` and `Prefix` dropped) it trusts via `bootstrap/app.php` `trustProxies(at: [...], headers: ...)` — see [[narrow-trusted-proxy-headers]].

## The prod stack

[compose.prod.yaml](compose.prod.yaml) (project `temari-prod`) runs seven services; the four app-tier services share the `*app-image` and `*app-env` anchors, while mysql and both redis instances stand on their own images. Secrets load from `/opt/temari/.env` on the host via `env_file:` (nothing flows through GitHub Actions secrets):

- **`app`** — the FrankenPHP server. The **only** service with a host port, and it's **loopback-only** `127.0.0.1:7001:7001`; cloudflared on the host reaches it there. Its container healthcheck uses shallow `/ready`, which proves Laravel can serve without dependency fan-out. Deep `/up` remains operationally separate: [VerifyDependencies](app/Listeners/VerifyDependencies.php) hooks Laravel's `DiagnosingHealth` event to ping the default MySQL connection, the `analytics` connection, both `default`/`cache` Redis connections, and Horizon's master-supervisor status. `stop_grace_period: 3s` — FrankenPHP doesn't exit early while connections are open, so under live traffic it otherwise rides out Docker's 10s default in full; 3s bounds that wait to what a normal render finishes well inside.
- **`horizon`** — `php artisan horizon` queue worker, `stop_grace_period: 60s` for graceful drain. Its healthcheck overrides the image's HTTP `/up` probe with `php artisan horizon:status` (exit `0` running / `1` paused / `2` inactive), so a wedged supervisor surfaces as `unhealthy` instead of a live-but-idle container.
- **`scheduler`** — `php artisan schedule:work`. Also overrides the image's HTTP probe, with a **liveness heartbeat**: [ScheduleHeartbeatCommand](app/Console/Commands/ScheduleHeartbeatCommand.php) is scheduled every minute in [routes/console.php](routes/console.php) to `SETEX` a unix timestamp on the **durable** `default` Redis connection, and the healthcheck re-runs the same command with `--check`, failing once that stamp is older than `STALE_AFTER_SECONDS` (300s). The service previously ran `healthcheck: disable: true`, so a wedged or dead `schedule:work` was completely silent — `ai:self-heal`, `strava:sync`, `weather:correct-forecast`, `streak:remind`, the daily briefing kickoff and the log pruning all just stopped, and the `$alertOnFailure` hooks in [routes/console.php](routes/console.php) could not see it because they only fire for commands that actually *run*. The probe's three states are distinguishable in `docker inspect --format '{{json .State.Health}}'`: `STALE` (with the age in seconds) / `MISSING` (no beat within the key's 1h TTL) / `UNKNOWN: redis unreachable`.
- **`pulse`** — combined daemon: `pulse:check` (Servers recorder, host root bind-mounted read-only at `/host`) + `pulse:work` (ingest drain), where either child dying exits the wrapper so Docker restarts it. The wrapper shell is PID 1 (the base image's entrypoint `exec`s straight into `command:`), so it traps `TERM`/`INT` to kill both children and exit immediately — a bare PID 1 default-ignores signals it hasn't trapped, which used to strand the container for the full `stop_grace_period` (now 10s, a backstop; was 30s) until SIGKILL. It rolls onto the new image **after** the deploy is green rather than with `app`/`horizon`, since it's monitoring-only and off the healthcheck's dependency graph — see "How a deploy runs" below.
- **`mysql`** — custom `temari/mysql:9.7` (MySQL 9.7 LTS, stock + initdb bootstrap) on a persistent `mysql_data` volume, tuned via command flags (`innodb-buffer-pool-size=1536M`, `max-connections=40`, `skip-name-resolve`), with GTIDs on (the 9.7 default) and binlogs kept 7 days ([compose.prod.yaml](compose.prod.yaml#L281)) for [point-in-time recovery](#point-in-time-recovery). Stays on the internal network only. No workflow builds or recreates it; changing its image is the manual [Upgrading MySQL](#upgrading-mysql) window.
- **`redis`** — durable store: `redis:8.8-alpine` (digest-pinned, and dev/CI pin the same digest), AOF `everysec`, `maxmemory 512mb` / `noeviction`, persistent `redis_data` volume. The healthcheck is a **write probe** (`SET`), not `ping`, because Redis answers PONG while still replaying AOF but rejects writes — a ping would let app/horizon connect mid-replay and read empty sessions.
- **`redis-cache`** — dedicated cache store split off `redis`: the same `redis:8.8-alpine` image, `maxmemory 256mb` / `allkeys-lru`, `appendonly no` and **no volume** (cache is ephemeral, rebuilds lazily). Split out so cache growth can only ever evict itself, never push the durable queue/session store into `noeviction` and stall enqueues. Reuses the same `SET` write-probe healthcheck.

`app`/`horizon`/`scheduler`/`pulse` all `depends_on` mysql + redis + redis-cache `service_healthy`, and each carries a per-service `deploy.resources` **limit** and **floor** ([compose.prod.yaml](compose.prod.yaml#L155) for `app`; [compose.prod.yaml](compose.prod.yaml#L176) `horizon`; [compose.prod.yaml](compose.prod.yaml#L201) `scheduler`; [compose.prod.yaml](compose.prod.yaml#L242) `pulse`). `horizon`'s floor is the largest of the three added — it is the queue worker on the narration/ingest critical path; `scheduler`'s is next, since a starved `schedule:work` is silent (see its healthcheck above); `pulse` is monitoring-only and gets the smallest. All seven floors sum to ~2.05 cores, well under the shared 4-core host.

### Redis DB partitioning

Two Redis instances, each addressed by DB number ([config/database.php](config/database.php) `redis` block + the env in [compose.prod.yaml](compose.prod.yaml)). The durable `redis` holds everything that must survive; the ephemeral `redis-cache` holds only the cache keyspace so it can evict freely under pressure:

| Instance | DB | Connection | Holds |
| --- | --- | --- | --- |
| `redis` | 0 | `default` | queue jobs + Horizon state + sessions (`SESSION_CONNECTION=default`) + the `scheduler:heartbeat` liveness stamp + cache locks, the scheduler mutexes included (the `redis` store's `lock_connection`) + the Monday scheduler-chain flags (the `durable` cache store, see [[scheduler]]) |
| `redis` | 2 | `pulse` | Pulse ingest buffer (`PULSE_REDIS_DB=2`) |
| `redis-cache` | 1 | `cache` | application cache (`REDIS_CACHE_DB=1`), including the shared Strava read buckets and the Strava-reported usage that caps them (the default store, which `RateLimiter` also uses; a restart empties both until Strava reports again, see [[strava-client]]) |

Session cookie name and the Redis/cache key prefixes are pinned to **fixed literals** (`SESSION_COOKIE`, `REDIS_PREFIX`, `CACHE_PREFIX`) instead of being derived from `APP_NAME`, so a cosmetic name/tagline edit can't rename the cookie or shift every key prefix and log everyone out. See [[fixed-session-cookie]].

### Horizon `ai` supervisor sizing

`supervisor-ai`'s `maxProcesses` in production ([config/horizon.php](config/horizon.php)) comes from `horizon.ai_processes` (env `HORIZON_AI_PROCESSES`, default `2`) rather than a hardcoded number — it needs to grow with the athlete count, but the homelab's 4-core host is shared with prod, so nothing here auto-scales; a human sets the env after reading a recommendation.

**Rule of thumb**: `ceil(athletes / 5)`, bounded to `[2, 6]`. `php artisan horizon:recommend-ai-processes` prints the current athlete count and this recommendation (also folded into the hourly `ai:self-heal` report), so the owner can compare it to the configured `HORIZON_AI_PROCESSES` and bump the env if they've drifted apart. The floor of 2 matches today's fixed value; the ceiling of 6 is a guess, not a measurement — revisit it once real multi-athlete Monday-burst data exists.

Why this matters: `plan:regenerate` can dispatch up to 9 rows per athlete, and the weekly recap + weekly-profile + plan regen all cluster at `00:01`-`00:07` WIB on Mondays (see [[bounded-self-heal-and-dead-letter]] for the retry/deadline model and #839 for the 240s per-run wall-clock deadline). At 2 workers, a 10-athlete Monday burst queues a lot of rows behind 2 processes; nothing is lost (idempotent generation + self-heal covers stragglers), but narration for later athletes lands later. Raising `maxProcesses` shortens that queue at the cost of `horizon`'s own CPU/memory share on the 1 vCPU / 1 GB container (see the `deploy.resources` floors above) — a tradeoff for the owner to make deliberately, never something the app decides for itself.

## Where the image is built

The `build` job ([.github/workflows/ci.yml](.github/workflows/ci.yml#L198)) runs on `ubuntu-26.04` (x64, deliberately: it emits the `linux/amd64` image the homelab runs, while the other hosted jobs use the arm64 image), needs only `changes`, and builds whenever a docker, backend or frontend input changed, so it runs in parallel with the test jobs. On a `push` it pushes `ghcr.io/<owner>/<repo>/app:<git-sha>` (a PR build only validates) using the job's own `GITHUB_TOKEN` widened to `packages: write` — **no new repository secret**. Layer cache is a registry cache (`cache-from`/`cache-to` on a `:buildcache` tag in the same GHCR package), not `type=gha`: the Actions cache is one 10 GB per-repo LRU that the hot composer and `node_modules` entries would evict a ~1 GB image cache out of between deploys.

This exists because the build used to run *inside* the deploy job on the homelab runner, putting a five-stage `docker build` on the same four cores that serve live prod traffic. The secondary win is an offsite image history: the host only ever held `:latest`/`:previous` locally, so recovering further back than one deploy meant rebuilding from the commit.

### Pull request check routing

On pull requests, the `changes` job in [.github/workflows/ci.yml](.github/workflows/ci.yml#L31) compares the PR diff with its merge base and passes changed paths to [scripts/ci/classify-checks.sh](scripts/ci/classify-checks.sh#L11), which sends each path to the jobs whose checks read it. A change to `ci.yml` itself runs every check. Backend CI runs for backend source, anything under `public/` (its structure tests check the layout and PWA assets exist), PHP/config/tooling inputs, shell helpers including the worktree tools, `Dockerfile`, Compose manifests, `.env.example`, `.nvmrc`, `deploy/`, `docker/`, every workflow and composite action under `.github/`, and the token-mirror inputs, because its tests read all of them. Frontend CI runs for frontend source, browser assets (`sw.js`, `offline.html`, `manifest.webmanifest`, and `robots.txt`), Vite/Vitest/Prettier/ESLint configuration including `.prettierignore` and `.editorconfig`, frontend package or TypeScript configuration, `resources/brand/` and `tests/fixtures/` (both read by Vitest), `.github/actions/` and `frontend-ci.yml`. `.gitignore` and `.gitattributes` run both suites. The image build runs on any backend or frontend input, and on `.dockerignore` or `public/.htaccess`. When backend CI does not run but a file that only the DB-free structure tests read changed (`resources/js/`, which `DesignTokenContrastTest` scans, `.dockerignore`, which `ProdImageAllowListTest` reads, and the docs that `DesignTokenDocsTest` and `LlmInventoryDocTest` read), the `backend-structure` job runs `pest --group=structure` on its own and feeds `ci-gate`, so a docs-only PR skips the backend shards, static analysis, coverage and the image build. [CiPathFilterTest](tests/Unit/Architecture/CiPathFilterTest.php) fails when a test reads a repo file through `base_path()`, `resource_path()` or `public_path()` and changing that file alone would not run a job that executes the test. A change under `scripts/worktree*` or `tests/scripts/` also runs the `worktree-races` job, which executes `tests/scripts/worktree-races.sh` for both lock backends on a hosted runner and feeds `ci-gate`. Other documentation, and `.github/` files that no check reads (`labeler.yml`, `dependabot.yml`, the PR template), run only the unconditional jobs. The diff disables rename detection so a renamed input's old path remains classified, and deletions continue to appear in the changed-path list. Pushes to `main` run every check.

> **Unverified in prod.** The GHCR split has never run against the real homelab host. The first deploy after it merges should be watched: GHCR package permissions, `ghcr.io` egress from the host, and the pull's effect on deploy wall-clock are all untested assumptions.

## How a deploy runs

The `deploy` job lives in its own workflow, [.github/workflows/deploy.yml](.github/workflows/deploy.yml), triggered by `workflow_run` when the `CI` workflow completes on `main`. It runs on the `[self-hosted, homelab]` runner only when that CI run concluded `success` and came from a `push`, so `ci-gate` (lint + pest + vitest + the repo guards, secret scan included) **and** `build` passed for the commit. The commit is `github.event.workflow_run.head_sha`, the sha CI tested, and the job uses it for the checkout ref, the image tag, the newest-commit check, the backup names, the summary and both alerts; in a `workflow_run` run, `github.sha` is the default branch's head instead. `concurrency: deploy-prod` with `cancel-in-progress: false` serializes deploys; GitHub keeps one pending deploy, a newer one replacing it. Once the job holds the lock, its first step compares the head sha with `git ls-remote origin refs/heads/main` and, when main has moved on, skips every later step as a success with "Superseded by <sha>, skipped" in the summary, so a late-finishing older commit can never roll prod back behind a newer one. In order:

1. **Require a running `mysql`.** The step fails when `$COMPOSE ps -q mysql` is empty, and only warns when the running container uses a different image than [compose.prod.yaml](compose.prod.yaml) pins. No deploy step builds, starts or recreates `mysql`: the data-layer `up` names only `redis redis-cache`, and every `up`/`run` passes `--no-deps` (pinned by [ProdMysqlDeployGuardTest](tests/Unit/Architecture/ProdMysqlDeployGuardTest.php)). An image change is the manual [Upgrading MySQL](#upgrading-mysql) window.
2. Pull `ghcr.io/<owner>/<repo>/app:<git-sha>` (token widened to `packages: read`).
3. Tag current `:latest` → `:previous` (rollback target) — **skipped** when the just-pulled image is already what `:latest` points to, so a re-run of a deploy for a sha that's already live doesn't collapse `:previous` onto the release it's re-running (that would make a rollback roll back to itself).
4. Tag the pulled image as `temari/app:latest`; bring up `redis` + `redis-cache` with `--wait --no-deps`. On a fresh box, start `mysql` by hand first (`docker compose -f compose.prod.yaml up -d --wait mysql` builds its image and self-initializes the volume). Every later step resolves the image through that local tag via the `x-app-image` anchor in [compose.prod.yaml](compose.prod.yaml), so nothing downstream is registry-aware.
5. Record whether maintenance was already active, then tag the new image with the git SHA.
6. **Backup** the app DB and the analytics schema to `/var/lib/temari-backups` (gzip, `pipefail`-guarded, tiny-dump check skipped only when the schema is genuinely empty). First, `Ensure the read-only backup user` ([deploy.yml](.github/workflows/deploy.yml#L136)) pipes [ensure-backup-user.sh](scripts/deploy/ensure-backup-user.sh#L23) into the mysql container as root (its own `MYSQL_ROOT_PASSWORD`): `CREATE USER IF NOT EXISTS 'temari_backup'@'127.0.0.1'`, an `ALTER USER` that resets the password from the host env, then `REVOKE ALL` and re-`GRANT` of `RELOAD, REPLICATION CLIENT` globally plus `SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES` on the two schemas, so every deploy converges the account to exactly those grants. The password is `DB_BACKUP_PASSWORD` in `/opt/temari/.env`, read fresh by [backup-password.sh](scripts/deploy/backup-password.sh#L5) through a one-off app container because the mysql container is never recreated and keeps the env it started with; a missing or empty value fails the step with an `::error::` naming the key. Every dump, manifest and table count then runs as `temari_backup` (the app user has no `RELOAD`) with `--set-gtid-purged=COMMENTED --loose-skip-masking-policies`: the dump records the GTID set it was taken at as a comment (the anchor for a binlog replay) without the `SET @@GLOBAL.GTID_PURGED` a restore into a live server would reject, and the masking flag skips policies no backup reads (the `--loose-` prefix lets an 8.4 client ignore it). Filenames carry a UTC timestamp plus the run id and run attempt after the sha, so a retried or re-run deploy for the same sha writes a new file beside the old one instead of overwriting it — see "Backup naming and retention" below.
7. **Quiesce** scheduler + horizon (SIGTERM, kept down) so no scheduled command/job is mid-run during the roll. `schedule:work` stops starting new ticks on SIGTERM and waits for its in-flight `schedule:run`, and the scheduler's `stop_grace_period: 120s` in [compose.prod.yaml](compose.prod.yaml) lets that run finish; Docker's 10s default used to SIGKILL any longer run (such as `strava:sync`), and a kill fires neither `onFailure` nor `ScheduledTaskFailed`. Horizon gets 60s to drain. The `stop` passes no `-t`, so these grace periods apply.
8. Detect pending migrations in both schemas. Only when either has work, enable maintenance unless the owner already did.
9. `migrate --force`, then `migrate --database=analytics --path=database/migrations/analytics --force` (one-shot `compose run --rm --no-deps app`).
10. Roll `app horizon` onto the new image (`up -d --no-deps`) — the recreate gives Horizon fresh workers, so no separate `horizon:terminate`.
11. `artisan optimize` (caches config inside the running container, where the real env is loaded).
12. Poll shallow `/ready`, then deep `/up`, and smoke-test `/login` (which must carry no `X-Powered-By` header) plus the PWA assets while maintenance is still active.
13. Resume `scheduler`, roll `pulse`, then lift maintenance only if this deploy enabled it. Owner maintenance remains untouched.
14. Prune SHA-tagged `temari/app` images that aren't `:latest`/`:previous`.

### Superseded main runs

CI's workflow-level concurrency group is `ci-<ref>` with `cancel-in-progress: true` on every ref, `main` included, so a newer push to `main` cancels the older commit's whole CI run. The superseded run shows as cancelled: `ci-gate` runs under `if: ${{ !cancelled() }}`, so it is skipped rather than failed, and a cancelled CI run never starts a deploy. A job that hits its `timeout-minutes` is different: the job's result is `cancelled` but the run is not, so `ci-gate` still runs, fails on that job, and the run reads red.

The cancellation never reaches a deploy. `Deploy` is a separate workflow run outside CI's concurrency group, and its `deploy-prod` group never cancels a started job. [CiConcurrencyTest](tests/Unit/Architecture/CiConcurrencyTest.php) pins both concurrency settings, the trigger and guard, the head sha, the newest-commit check and the rollback step.

`workflow_run` has two properties to keep in mind:

- GitHub always runs the copy of `deploy.yml` on `main`, never the one in the commit CI tested. A PR never runs the deploy, so a change to `deploy.yml` is checked only by actionlint and the structure tests, and first runs for real on the deploy of its own merge commit.
- The deploy starts about 10–30 s after CI completes, the time GitHub takes to deliver the `workflow_run` event.

Re-running a main CI run to success starts a new `Deploy` run for that commit, and re-running a `Deploy` run retries the same head sha. Both still pass through the newest-commit check.

### Migrations must be expand/contract

The deploy's migration-only maintenance window prevents ordinary users from running the previous code against a changing schema. That is a safety net, not permission to collapse schema evolution into one release: admins remain admitted, operational commands still boot the application, and rollback to old code is unsafe once schema work may have started.

Write schema changes as **expand/contract split across two deploys**:

1. **Expand** (deploy 1): add the new column/table/enum value; backfill; make new code write both old and new. Never remove or narrow anything the currently-live code depends on.
2. **Contract** (deploy 2, after deploy 1 is fully rolled): drop the now-unused old column / tighten the constraint, once no running code references it.

CI runs [check-migration-safety.php](scripts/check-migration-safety.php) against migration files changed by the PR. Destructive operations in `up()`—drops, renames, column `change()`, data deletion and destructive raw SQL—fail unless the reviewed, later contract migration places `/** @contract-migration */` above `up()`. Rollback operations in `down()` are ignored. The marker records an explicit expand/contract decision; it is not a generic bypass.

Note what this does **not** buy: the roll itself is not zero-downtime. There is one `app` container on one loopback port and no second replica, and the roll is a plain `up -d --no-deps app horizon` — compose stops the old container and starts the new one in place, so requests are refused for that window. Measured under live traffic before `app`'s `stop_grace_period: 3s` existed: ~11.2s unavailable, ~10.4s of which was Docker's 10s default grace period rather than genuine restart cost. `/ready` observes when the replacement can serve; it does not gate a cutover.

## Rollback

A failed deploy **tries to roll itself back first**. The `Roll back on failure` step runs under `if: failure()`: it restarts the quiesced scheduler/horizon/pulse, re-tags `:previous` → `:latest`, rolls the containers back and re-polls `/up`. The hosted `notify` job then sends one Telegram alert either way, saying whether prod auto-rolled back or needs manual recovery, and it alerts even when the deploy job died before any step ran (see "Hosted-runner failure alerts" below). So a red deploy without pending migrations usually means prod is already back on the previous image — check the alert before intervening by hand.

**It refuses to auto-roll when migrations were pending.** `Detect pending migrations` runs `migrate:status --pending=1` on both connections before migrating and records the existing `MIGRATIONS_APPLIED` safety flag. When that is `true`—including when it is unset, which defaults fail-safe—the rollback step deliberately leaves migration maintenance active and requires manual recovery. A migration command can apply one file and fail on the next, so “the step failed” cannot prove the old image is schema-compatible. Owner-enabled maintenance also remains active. Recover with the `Rollback prod` workflow plus `./scripts/restore-db.sh <backup>`.

Neither path has ever fired in prod. The restore half is now exercised nightly in CI against a throwaway database — [restore-db-exercise](.github/workflows/nightly-audit.yml) migrates a fresh schema on **both** the default and analytics connections, dumps each, and runs [scripts/restore-db.sh](scripts/restore-db.sh) for real on both (its flags, env expectations, and the gzip/streaming path, including the `analytics-*` filename branch) via the `COMPOSE_FILE`/`MYSQL_SERVICE` overrides added for that job — but only that script's mechanics, not a real backup. It restores twice: first over a simulated failed migration (a table created after the dump plus deleted sentinel rows, in both schemas), asserting the extra table is gone, then again after dropping both schemas. Its dumps run as `temari_backup`, created by the same [ensure-backup-user.sh](scripts/deploy/ensure-backup-user.sh#L23) twice to prove it re-runs cleanly. It then drills [point-in-time recovery](#point-in-time-recovery) ([nightly-audit.yml](.github/workflows/nightly-audit.yml#L267)): a fresh dump, a sentinel row written after it, one more row after that, a restore of the dump, then a replay to the sentinel's GTID set, asserting the sentinel is back and the later row is not.

**A restore runs as the app user.** [restore-db.sh](scripts/restore-db.sh#L87) drops the dump's `SET @@SESSION.SQL_LOG_BIN` lines, which need admin rights, so the restore is binlogged like any other write. **A restore resets its target schema first.** The script drops every table in the schema it restores into, in the same `mysql` session that loads the dump, so a table created by the migration being undone cannot survive, collide with the fixed migration's `Schema::create` and fail the next deploy at its migrate step. The target is the container's `DB_DATABASE` for the app dump, and the schema named by the `USE` line of an `analytics-*` dump (dumped with `--databases`). The script refuses an app-named dump that carries its own `USE`, and an `analytics-*` dump without one. The confirmation prompt (skipped only by `RESTORE_DB_ASSUME_YES=1`) names that schema and comes before anything is dropped. Restoring a **real** backup has a dry run too: the [Restore dry-run](.github/workflows/restore-dry-run.yml) workflow runs weekly (Sunday 04:07 WIB) on the newest `nightly-*.sql.gz`/`analytics-nightly-*.sql.gz` pair, and on demand with a `kind` input: `nightly` (the default) or `pre-deploy`, which picks the newest (or a given `backup_sha`) `pre-deploy-*.sql.gz`/`analytics-pre-deploy-*.sql.gz` pair from `/var/lib/temari-backups`. It restores both into a throwaway MySQL from [deploy/restore-dry-run-compose.yml](deploy/restore-dry-run-compose.yml) (prod's image + env_file, its own `temari-restore` compose project, no published ports), and verifies it before tearing the throwaway stack down. A `nightly` pair is checked against the manifest its backup wrote just before dumping: every table must be restored with at least its dump-time row count ([check-restore-manifest.sh](scripts/deploy/check-restore-manifest.sh)), because live keeps changing after the dump (the first nightly dry-run, on 2026-10-02, failed against live only because a deploy had added six tables since the dump). A dump from before manifests existed skips the count check with a warning. A `pre-deploy` pair, taken minutes before its deploy, compares table lists plus a fixed set of row counts against live with read-only queries. Count query failures and empty or non-numeric results fail verification; valid counts retain the existing checks that reject throwaway counts above live and non-empty live tables restored as empty, while allowing historical count differences. It shares the `deploy-prod` concurrency group so it can't overlap a real deploy, and a failure alerts through a hosted `notify` job (see "Hosted-runner failure alerts" below). Rollback itself and the "migrations applied → refuse rollback" branch remain untested — a rollback dry run is still a separate owner-run step.

### Backup naming and retention

A deploy backup used to be named `pre-deploy-<sha>.sql.gz` — keyed on the sha alone, so a retried or manually re-run deploy for that sha overwrote the only backup with whatever state existed at the retry, silently. `Compute backup filename suffix` in [.github/workflows/deploy.yml](.github/workflows/deploy.yml) now appends a UTC timestamp plus `github.run_id` and `github.run_attempt`, e.g. `pre-deploy-<sha>-<timestamp>-<run_id>-<run_attempt>.sql.gz` (and `analytics-pre-deploy-<...>.sql.gz`, sharing the identical suffix after its own prefix — that's how [restore-dry-run.yml](.github/workflows/restore-dry-run.yml) pairs them without parsing the sha back out). Both backup steps refuse to write over an existing path as a second line of defense.

[scripts/deploy/verify-and-prune-backup.sh](scripts/deploy/verify-and-prune-backup.sh) prunes by count-and-age (keep the newest 10 regardless of age, then drop anything past the retention window) but, given a group prefix, first checks whether a candidate is the newest backup sharing its sha — if so it's kept no matter how old, so a sha is never left with zero backups. Deploy backups pass `7` days retention and their prefix (`pre-deploy-`/`analytics-pre-deploy-`); nightly backups (below) pass `14` days and no prefix, since they aren't sha-keyed.

## Nightly backup and disk hygiene

A deploy backup only exists because a deploy happened — a quiet week between deploys left no recovery point at all. [.github/workflows/nightly-backup.yml](.github/workflows/nightly-backup.yml) runs on the self-hosted homelab runner independent of shipping code: cron `40 16 * * *` (23:40 WIB, chosen the same way as the nightly audit below — off the hour, and after the day's data is settled but before the `streak:settle`-through-`ai:trend-read` scheduled-job window in [routes/console.php](routes/console.php)), plus `workflow_dispatch`. It dumps both schemas with the same `mysqldump` flags the deploy steps use, verifies each with the shared prune script, and names them `nightly-<timestamp>.sql.gz` / `analytics-nightly-<timestamp>.sql.gz` — distinct from `pre-deploy-*` so the two rotations never prune each other — kept 14 days. Just before each dump it writes `nightly-<timestamp>.manifest` / `analytics-nightly-<timestamp>.manifest` (every table with its exact row count, via [count-rows-query.sh](scripts/deploy/count-rows-query.sh)) for the weekly restore dry-run; a manifest failure only warns and never stops the dump, and a manifest is removed once its dump is pruned. It shares the `deploy-prod` concurrency group so a dump can't land mid-migration. A backup failure pushes one Telegram alert through the hosted `notify` job (see "Hosted-runner failure alerts" below); a silent failure would be worse than none. A backup job that dies before its steps, or never starts, is covered from a hosted runner (see "Hosted-runner failure alerts" below).

After cleanup, `Binlog disk headroom` ([nightly-backup.yml](.github/workflows/nightly-backup.yml#L138)) sums the binlog files written in the last 7 days (the retention window) and the night's two dumps, and fails the job, so `notify` alerts, when that total exceeds the smaller of the free space under the mysql data directory and under `/var/lib/temari-backups`. It also fails when no binlog was written in 7 days, since point-in-time recovery would then have nothing to replay. Each run writes the numbers to the job summary.

Disk cleanup rides the same job, strictly after a successful backup (cleanup steps use `continue-on-error`, so a cleanup failure can never fail the backup that already succeeded): `docker builder prune` with an age filter, then [scripts/deploy/select-images-to-prune.sh](scripts/deploy/select-images-to-prune.sh) removes sha-tagged `ghcr.io/<owner>/<repo>/app` images beyond the most recent 10 — the pulled images the `build` job pushes one per merged commit, which nothing else ever cleaned up (the deploy's own prune step at the end of this doc only touches the local `temari/app` re-tags, not the ghcr.io-sourced ones). The selection logic reads plain `CREATED<TAB>ID<TAB>TAG` lines from stdin rather than calling `docker` itself, so it can run against a fixture in tests; it never selects `latest`, `previous`, anything not shaped like a full sha, or anything a running container has by id or by `repo:tag`.

## Nightly dependency audit alert

[.github/workflows/nightly-audit.yml](.github/workflows/nightly-audit.yml) runs `composer audit` and `npm audit` nightly on `ubuntu-26.04-arm` (no install, both read the lock files directly) and does not go through `MaintainerAlerter` — that path only exists inside the prod app container on the homelab runner, which this workflow never touches. Instead, an `alert-on-failure` job pushes a Telegram message directly via `curl` when either audit job fails, using two repository secrets: `TELEGRAM_BOT_TOKEN` and `TELEGRAM_MAINTAINER_CHAT_ID`.

**Both secrets are set (2026-09-10).** The alert step now pushes on a red nightly audit. For whoever rotates them: the bot token is from the same Telegram bot `MaintainerAlerter` uses, and the chat id is the maintainer's own Telegram chat id.

## Hosted-runner failure alerts

An alert step inside a self-hosted job never runs when the job dies before its steps: the homelab runner is offline, or "Set up job" fails (a CI run on 2026-09-26 failed there after three action-tarball download timeouts, with no alert), so `deploy`, `nightly-backup` and `restore-dry-run` alert only from a `notify` job that runs on `ubuntu-26.04-arm` when the self-hosted job's result is `failure` (for `deploy`, also `cancelled`), calling the reusable [.github/workflows/maintainer-alert.yml](.github/workflows/maintainer-alert.yml), which pushes the given message plus the run URL to Telegram with the same two secrets as the nightly audit (and skips when either is unset). `deploy`'s `Record the failure note for the alert` step hands the rollback note to `notify` as the `failure_note` job output, so each failure sends one message. `notify` sits after `deploy` in `deploy.yml`, outside CI, so it can never block a merge. A job-level timeout is a cancellation (`cancelled`), not a `failure`; `deploy`'s `notify` also alerts on `cancelled`, so a deploy dropped by a queued backup or drill pages the owner, who re-runs it, while `nightly-backup` and `restore-dry-run` alert on `failure` only.

A backup that never starts has no failed job to react to. [.github/workflows/backup-watchdog.yml](.github/workflows/backup-watchdog.yml) runs daily at 08:37 WIB on `ubuntu-26.04-arm` with `actions: read`, reads the newest successful `Nightly backup` run via `gh run list`, and fails, then alerts through the same reusable workflow, when that run started 26 or more hours ago or no successful run exists. Running nine hours after the 23:40 WIB backup means one missed night shows up as about 33 hours. A manual run takes a lower `max_age_hours` to exercise the alert.

**Overruns reach that failure path by design.** Timeouts are set per *step* (the image pull, both dumps, both migrates, and the rollback step) rather than only on the job, because a job-level timeout is a *cancellation* and GitHub skips every `if: failure()` step when one trips — an overrun would otherwise strand a half-deployed stack with no rollback, no alert and no summary. The job cap is a last-resort backstop sitting above the sum of the step caps. Every `curl` in the deploy and rollback workflows carries `--max-time` for the same reason: a worker that accepts a connection but never answers would otherwise hang a retry loop past the backstop.

### Manual rollback

Every successful deploy leaves `temari/app:previous` and `temari/app:<git-sha>` on the host. To roll back the most recent deploy by hand, re-tag `:previous` → `:latest`, `up -d --no-deps app horizon scheduler`, and `horizon:terminate`. The full commands and the `/opt/temari/.env` setup table live in the Deployment section of [README.md](README.md).

The `Rollback prod` workflow ([.github/workflows/rollback.yml](.github/workflows/rollback.yml)) is **deliberately registry-unaware**: it only inspects and re-tags local `temari/app` images. Building on a hosted runner does not change that, because the deploy still lands `temari/app:latest` as a local tag on the host and still tags the outgoing one `:previous` before it does. Keep it that way — a rollback that has to reach the network is a rollback that can fail when the network is why you're rolling back.

**On the host you can still only go back one deploy.** The prune step runs `if: always()` on every deploy and deletes every `temari/app` tag except `:latest`, `:previous` and the SHA just deployed ([.github/workflows/deploy.yml](.github/workflows/deploy.yml#L322)), so the host holds exactly two recoverable images. What is new is that GHCR keeps a `:<git-sha>` per deploy, so recovering further back is now a `docker pull ghcr.io/<owner>/<repo>/app:<sha>` + local re-tag instead of a rebuild from source.

## Point-in-time recovery

A dump alone returns prod to the moment it was taken, up to a day behind for a nightly. Prod binlogs every write with GTIDs on and keeps 7 days of binlogs ([compose.prod.yaml](compose.prod.yaml#L281)), and every dump records the GTID set it was taken at, so restoring a dump and replaying the binlog after it reaches any moment in that window. [replay-binlogs.sh](scripts/deploy/replay-binlogs.sh#L75) does the replay inside the mysql container as root: it reads the start set from the dump, keeps only that schema's events (`--database`), and stops at a GTID set (replayed up to and including its last transaction) or a UTC datetime (the first event at or after it is skipped). It passes `--skip-gtids`, because this server already counts every replayed GTID as executed and would otherwise skip them all silently. The mysql image bakes in `mysqlbinlog` at the server's exact version ([Dockerfile](docker/mysql/Dockerfile#L14)), so a replay needs no network; the script fails if it is missing ([replay-binlogs.sh](scripts/deploy/replay-binlogs.sh#L70)). The nightly audit drills the whole path (see [Rollback](#rollback)).

Run it on the host, from a fresh checkout of `main`, through `sudo` as in [Upgrading MySQL](#upgrading-mysql), with nothing deploying or merging. Only dumps taken after the backup user shipped carry a GTID position; pick one newer than 7 days so its binlogs still exist.

```bash
COMPOSE="sudo docker compose -f compose.prod.yaml"
ROOT_SQL='export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; mysql -h 127.0.0.1 -uroot -e "$Q"'

# 1. Find the stop point. List the binlogs, then read the one around the incident:
#    each transaction starts with SET @@SESSION.GTID_NEXT= '<uuid>:<n>', followed by its table events.
$COMPOSE exec -T -e Q="SHOW BINARY LOGS" mysql sh -c "$ROOT_SQL"
$COMPOSE exec -T -e Q="SHOW BINLOG EVENTS IN 'binlog.000123' LIMIT 200" mysql sh -c "$ROOT_SQL"
#    The stop point is <uuid>:1-<n-1> for the last good transaction n-1, or a UTC 'YYYY-MM-DD HH:MM:SS'.

# 2. Maintenance on, writers off.
temari down
$COMPOSE stop scheduler horizon pulse

# 3. The newest dump taken before the stop point, and the position it recorded.
ls -lt /var/lib/temari-backups/*.sql.gz | head
DUMP=/var/lib/temari-backups/nightly-<timestamp>.sql.gz
gunzip -c "$DUMP" | grep -m1 GTID_PURGED

# 4. Restore the dump, then replay to the stop point.
sudo ./scripts/restore-db.sh "$DUMP"
sudo ./scripts/deploy/replay-binlogs.sh "$DUMP" '<uuid>:1-<n-1>'
#    For the analytics schema, repeat step 4 with its analytics-<...>.sql.gz from the same run;
#    it carries its own GTID position.

# 5. Check the rows the incident touched, then bring services back.
$COMPOSE start scheduler horizon pulse
temari up
curl -fsS -o /dev/null --retry 6 --retry-delay 5 --retry-all-errors http://127.0.0.1:7001/up && echo "up OK"
```

The restore and the replay are binlogged like any other write, so a second recovery later starts from a newer dump. A replay that fails part-way leaves the schema between the dump and the stop point: run step 4 again from the restore.

## Upgrading MySQL

The `mysql` image changes only in a manual, announced window. Deploys never build, start or recreate `mysql` (step 1 of "How a deploy runs"). After a merge that renames the tag in [compose.prod.yaml](compose.prod.yaml), deploys keep the old container running and warn until this window has run. A tag that is still missing makes the restore dry run fail instead of pulling it from a registry ([deploy/restore-dry-run-compose.yml](deploy/restore-dry-run-compose.yml) sets `pull_policy: never`). Every dump already carries the 9.7-safe flags (step 6 above).

**Window.** No deploy is running or queued (`gh run list --workflow deploy.yml --status in_progress`, and again with `--status queued`), no `main` CI run is in progress (its success starts a deploy), and nothing merges until the window ends. Keep well clear of the nightly backup (23:40 WIB) and the Sunday restore dry run (04:07 WIB), both of which share the `deploy-prod` lock. Users see the maintenance page from step 3 to step 8.

Run each command on its own, on the host, from a fresh checkout of `main` at the merge commit. The 8.4 → 9.7 values are shown; set `OLD`/`NEW` for a later upgrade. Compose reads `/opt/temari/.env`, which a personal login cannot read, so `COMPOSE` runs it through `sudo` from an interactive SSH session.

```bash
git clone https://github.com/nukipratama/temari.git /tmp/temari-mysql && cd /tmp/temari-mysql
COMPOSE="sudo docker compose -f compose.prod.yaml"; OLD=temari/mysql:8.4; NEW=temari/mysql:9.7
VOL=temari-prod_mysql_data; COPY=temari-prod_mysql_data_before_upgrade

# 1. Preflight: mysql runs $OLD, the image is kept for rollback, and the disk fits one more copy of the volume.
$COMPOSE ps mysql
docker image inspect "$OLD" --format '{{.Id}}'
docker run --rm --entrypoint du -v "$VOL":/d:ro "$OLD" -sh /d
df -h /var/lib/docker

# 2. Build the new image. The running container is untouched.
$COMPOSE build mysql
docker run --rm --entrypoint mysqld "$NEW" --version

# 3. Maintenance on, writers off.
$COMPOSE exec -T app php artisan down
$COMPOSE stop scheduler horizon pulse

# 4. Verified backup, run from your machine. The dry run restores the dump into a throwaway $NEW
#    and checks every table against its manifest. Continue only when both runs succeed.
gh workflow run nightly-backup.yml --ref main
gh workflow run restore-dry-run.yml --ref main -f kind=nightly
APP_MANIFEST=$(ls -t /var/lib/temari-backups/nightly-*.manifest | head -1); echo "$APP_MANIFEST"

# 5. Cold copy of the data volume, the fast rollback path.
$COMPOSE stop mysql
docker volume create "$COPY"
docker run --rm --entrypoint cp -v "$VOL":/from:ro -v "$COPY":/to "$OLD" -a /from/. /to/
docker run --rm --entrypoint sh -v "$VOL":/a:ro -v "$COPY":/b:ro "$OLD" -c 'cd /a && find . -type f -exec md5sum {} + | sort > /tmp/a; cd /b && find . -type f -exec md5sum {} + | sort > /tmp/b; [ "$(md5sum < /tmp/a)" = "$(md5sum < /tmp/b)" ] && echo "copy identical"'

# 6. Recreate mysql only, on $NEW. This step upgrades the data dictionary and cannot be undone in place.
$COMPOSE up -d --no-deps --wait --wait-timeout 600 mysql
$COMPOSE logs --no-log-prefix mysql | grep -E 'upgrad|ERROR'

# 7. Checks. Every one must pass before step 8.
$COMPOSE exec -T mysql sh -c 'MYSQL_PWD="$DB_PASSWORD" mysql -h 127.0.0.1 -N -u"$DB_USERNAME" -e "SELECT VERSION()"'
$COMPOSE run --rm --no-deps app php artisan migrate:status --pending=1 && echo "default: none pending"
$COMPOSE run --rm --no-deps app php artisan migrate:status --database=analytics --path=database/migrations/analytics --pending=1 && echo "analytics: none pending"
$COMPOSE exec -T mysql sh -c 'MYSQL_PWD="$DB_PASSWORD" mysql -h 127.0.0.1 -N -B -u"$DB_USERNAME" "$DB_DATABASE" -e "SHOW TABLES"' | scripts/deploy/count-rows-query.sh > /tmp/count-rows.sql
$COMPOSE exec -T mysql sh -c 'MYSQL_PWD="$DB_PASSWORD" mysql -h 127.0.0.1 -N -B -u"$DB_USERNAME" "$DB_DATABASE"' < /tmp/count-rows.sql | sort > /tmp/live-counts.tsv
sort "$APP_MANIFEST" | diff - /tmp/live-counts.tsv && echo "row counts match the manifest"
DB_BACKUP_PASSWORD=$(sudo scripts/deploy/backup-password.sh) && export DB_BACKUP_PASSWORD
$COMPOSE exec -T -e DB_BACKUP_PASSWORD mysql sh -c 'export MYSQL_PWD="$DB_BACKUP_PASSWORD"; mysqldump -h 127.0.0.1 --single-transaction --quick --no-tablespaces --set-gtid-purged=COMMENTED --loose-skip-masking-policies -utemari_backup "$DB_DATABASE"' | gzip > /tmp/mysql-check.sql.gz && gzip -t /tmp/mysql-check.sql.gz && ls -l /tmp/mysql-check.sql.gz

# 8. Services back. `/up` fails until Horizon registers its supervisor, so it is retried.
$COMPOSE restart app
$COMPOSE start scheduler horizon pulse
$COMPOSE exec -T app php artisan up
curl -fsS http://127.0.0.1:7001/ready && curl -fsS -o /dev/null --retry 6 --retry-delay 5 --retry-all-errors http://127.0.0.1:7001/up && echo "up OK"
rm -f /tmp/mysql-check.sql.gz /tmp/count-rows.sql /tmp/live-counts.tsv
```

**Rollback**, if step 6 or 7 fails. The upgraded data dictionary cannot be opened by `$OLD`, so put the cold copy back and point the container at `$OLD`:

```bash
$COMPOSE stop mysql
docker run --rm --entrypoint sh -v "$VOL":/data -v "$COPY":/from:ro "$OLD" -c 'find /data -mindepth 1 -delete && cp -a /from/. /data/'
printf 'services:\n  mysql:\n    image: %s\n' "$OLD" > /tmp/mysql-rollback.yml
$COMPOSE -f /tmp/mysql-rollback.yml up -d --no-deps --wait mysql
# then step 8, and revert the merge that renamed the tag
```

Until that revert lands, deploys only warn that mysql runs `$OLD`. If the cold copy is unusable, start `$OLD` on an empty volume, then restore the step-4 dumps with `./scripts/restore-db.sh`. Keep `$COPY` and `$OLD` for a week of normal running, then remove them with `docker volume rm "$COPY"` and `docker rmi "$OLD"`.
