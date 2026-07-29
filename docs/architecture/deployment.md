---
title: Deployment & runtime
description: Multi-stage FrankenPHP/Octane image built on a hosted runner and pushed to GHCR, the loopback-only prod compose stack behind a Cloudflare Tunnel, Redis DB partitioning, and the GitHub Actions pull/migrate/roll/rollback flow on a single homelab host
tags: [architecture, infra]
status: living
reviewed: 2026-07-29
code_refs:
  - Dockerfile
  - compose.prod.yaml
  - docker/Caddyfile
  - .github/workflows/ci.yml
  - config/octane.php
  - config/database.php
  - routes/console.php
  - README.md
---

# Deployment & runtime

How Temari is built into an image, run as a compose stack, and continuously deployed to **one self-hosted homelab host** on every push to `main`. The image is *built* on a GitHub-hosted runner and pulled from GHCR; only the run stack lives on the homelab. The host sits behind an existing Cloudflare Tunnel; nothing in this repo provisions the tunnel itself. Start here before touching the [Dockerfile](Dockerfile), [compose.prod.yaml](compose.prod.yaml), or the build/deploy jobs in [.github/workflows/ci.yml](.github/workflows/ci.yml).

## The image (multi-stage)

[Dockerfile](Dockerfile) is one file with five stages, all pinned by digest so a floating tag can't drift the runtime out from under us:

- **`dev`** — local-only FrankenPHP target (traditional mode, no Octane worker). Bakes PHP extensions + the pinned Node toolchain + `docker/Caddyfile.dev`; source is volume-mounted at runtime.
- **`vendor`** — `composer install --no-dev`, then `dump-autoload --classmap-authoritative`. The `package:discover` hook is **deferred** out of this stage (the `composer:2` image has no redis ext, so a provider boot would crash).
- **`assets`** — `npm ci` + `npm run build` (Vite + `@tailwindcss/vite`), pulling `vendor/` in because some packages publish CSS/JS.
- **runtime** (final, unnamed) — fresh FrankenPHP, copies `vendor/` and `public/build`, installs the runtime extensions (`pdo_mysql`, `redis`, `intl`, `bcmath`, `opcache`, `pcntl`), then runs the deferred `package:discover` with `CACHE_STORE=array`.

`config:cache` is deliberately **not** baked into the image — build time has no `.env`, so `env()` would freeze PHP defaults (e.g. `DB_CONNECTION` → `sqlite` in [config/database.php](config/database.php)) into the cache. Caching happens at deploy time instead, inside the running container. See `package:discover` + the `Optimize caches` step ([.github/workflows/ci.yml](.github/workflows/ci.yml)) and [[defer-config-cache]].

## Runtime: FrankenPHP + Octane

The runtime stage serves on **`:7001`** plain HTTP — TLS terminates at Cloudflare, so `auto_https off` in [docker/Caddyfile](docker/Caddyfile). The live worker loop is the Caddyfile **`frankenphp { worker { ... } }`** directive (FrankenPHP's `frankenphp-worker.php`), **not** `octane:start`. So the recycle/sizing knobs are `num 2` and `env MAX_REQUESTS 2000` in the Caddyfile, while `OCTANE_MAX_REQUESTS` / `FRANKENPHP_NUM_WORKERS` in [compose.prod.yaml](compose.prod.yaml) are inert (kept only in case we ever switch to `octane:start`). `FRANKENPHP_NUM_THREADS=4` is a concrete count in the [Dockerfile](Dockerfile), not `auto`, because docker-out-of-docker means `auto` would size off the host's full core count and over-subscribe the 2-CPU-capped container. [config/octane.php](config/octane.php) still supplies `server => frankenphp` and the per-request flush listeners. FrankenPHP **must** load `/etc/frankenphp/Caddyfile` — any other path silently falls back to the image default (no worker directive, no cache headers).

### Caddy front (in-container)

[docker/Caddyfile](docker/Caddyfile) handles static caching (`/build/*` immutable, favicons 7d) and sets **no** caching on dynamic responses; the only HTTP caching on an app page is the app-layer conditional GET the three history routes opt into, described in [[frontend-architecture]]. The ops dashboards (`/horizon`, `/pulse`, `/ai-usage`) and the Livewire update endpoint they POST through are authorized inside Laravel by the `is_admin` maintainer flag — there is no edge basic-auth wall (removed once `is_admin` became the single authz source); Cloudflare Access fronts the edge. `trusted_proxies static private_ranges` is set, and the app narrows both *which peers* (loopback + RFC1918 ranges) and *which headers* (`X-Forwarded-For`/`Proto`/`Port` only, `Host` and `Prefix` dropped) it trusts via `bootstrap/app.php` `trustProxies(at: [...], headers: ...)` — see [[narrow-trusted-proxy-headers]].

## The prod stack

[compose.prod.yaml](compose.prod.yaml) (project `temari-prod`) runs seven services; the four app-tier services share the `*app-image` and `*app-env` anchors, while mysql and both redis instances stand on their own images. Secrets load from `/opt/temari/.env` on the host via `env_file:` (nothing flows through GitHub Actions secrets):

- **`app`** — the FrankenPHP server. The **only** service with a host port, and it's **loopback-only** `127.0.0.1:7001:7001`; cloudflared on the host reaches it there. HTTP `/up` healthcheck: [VerifyDependencies](app/Listeners/VerifyDependencies.php) hooks Laravel's `DiagnosingHealth` event to also ping the default MySQL connection, the `analytics` connection, both `default`/`cache` Redis connections, and Horizon's master-supervisor status, so `/up` reflects the whole stack rather than just "PHP booted."
- **`horizon`** — `php artisan horizon` queue worker, `stop_grace_period: 60s` for graceful drain. Its healthcheck overrides the image's HTTP `/up` probe with `php artisan horizon:status` (exit `0` running / `1` paused / `2` inactive), so a wedged supervisor surfaces as `unhealthy` instead of a live-but-idle container.
- **`scheduler`** — `php artisan schedule:work`. Also overrides the image's HTTP probe, with a **liveness heartbeat**: [ScheduleHeartbeatCommand](app/Console/Commands/ScheduleHeartbeatCommand.php) is scheduled every minute in [routes/console.php](routes/console.php) to `SETEX` a unix timestamp on the **durable** `default` Redis connection, and the healthcheck re-runs the same command with `--check`, failing once that stamp is older than `STALE_AFTER_SECONDS` (300s). The service previously ran `healthcheck: disable: true`, so a wedged or dead `schedule:work` was completely silent — `ai:self-heal`, `strava:sync`, `weather:correct-forecast`, `streak:remind`, the daily briefing kickoff and the log pruning all just stopped, and the `$alertOnFailure` hooks in [routes/console.php](routes/console.php) could not see it because they only fire for commands that actually *run*. The probe's three states are distinguishable in `docker inspect --format '{{json .State.Health}}'`: `STALE` (with the age in seconds) / `MISSING` (no beat within the key's 1h TTL) / `UNKNOWN: redis unreachable`.
- **`pulse`** — combined daemon: `pulse:check` (Servers recorder, host root bind-mounted read-only at `/host`) + `pulse:work` (ingest drain), where either child dying exits the wrapper so Docker restarts it.
- **`mysql`** — custom `temari/mysql:8.4` (stock + initdb bootstrap) on a persistent `mysql_data` volume, tuned via command flags (`innodb-buffer-pool-size=1536M`, `max-connections=40`, `skip-name-resolve`). Stays on the internal network only.
- **`redis`** — durable store: `redis:8-alpine`, AOF `everysec`, `maxmemory 512mb` / `noeviction`, persistent `redis_data` volume. The healthcheck is a **write probe** (`SET`), not `ping`, because Redis answers PONG while still replaying AOF but rejects writes — a ping would let app/horizon connect mid-replay and read empty sessions.
- **`redis-cache`** — dedicated cache store split off `redis`: `redis:8-alpine`, `maxmemory 256mb` / `allkeys-lru`, `appendonly no` and **no volume** (cache is ephemeral, rebuilds lazily). Split out so cache growth can only ever evict itself, never push the durable queue/session store into `noeviction` and stall enqueues. Reuses the same `SET` write-probe healthcheck.

`app`/`horizon`/`scheduler`/`pulse` all `depends_on` mysql + redis + redis-cache `service_healthy`, and each carries a per-service `deploy.resources` **limit** and **floor** ([compose.prod.yaml:155-156](compose.prod.yaml) for `app`; [compose.prod.yaml:176-177](compose.prod.yaml) `horizon`; [compose.prod.yaml:201-202](compose.prod.yaml) `scheduler`; [compose.prod.yaml:242-243](compose.prod.yaml) `pulse`). `horizon`'s floor is the largest of the three added — it is the queue worker on the narration/ingest critical path; `scheduler`'s is next, since a starved `schedule:work` is silent (see its healthcheck above); `pulse` is monitoring-only and gets the smallest. All seven floors sum to ~2.05 cores, well under the shared 4-core host.

### Redis DB partitioning

Two Redis instances, each addressed by DB number ([config/database.php](config/database.php) `redis` block + the env in [compose.prod.yaml](compose.prod.yaml)). The durable `redis` holds everything that must survive; the ephemeral `redis-cache` holds only the cache keyspace so it can evict freely under pressure:

| Instance | DB | Connection | Holds |
| --- | --- | --- | --- |
| `redis` | 0 | `default` | queue jobs + Horizon state + sessions (`SESSION_CONNECTION=default`) + the `scheduler:heartbeat` liveness stamp |
| `redis` | 2 | `pulse` | Pulse ingest buffer (`PULSE_REDIS_DB=2`) |
| `redis-cache` | 1 | `cache` | application cache (`REDIS_CACHE_DB=1`) |

Session cookie name and the Redis/cache key prefixes are pinned to **fixed literals** (`SESSION_COOKIE`, `REDIS_PREFIX`, `CACHE_PREFIX`) instead of being derived from `APP_NAME`, so a cosmetic name/tagline edit can't rename the cookie or shift every key prefix and log everyone out. See [[fixed-session-cookie]].

## Where the image is built

The `build` job ([.github/workflows/ci.yml:309](.github/workflows/ci.yml)) runs on `ubuntu-latest`, gated by the same `push`-to-`main` condition as `deploy` but with no `needs`, so it builds in parallel with the test jobs. It pushes `ghcr.io/<owner>/<repo>/app:<git-sha>` using the job's own `GITHUB_TOKEN` widened to `packages: write` — **no new repository secret**. Layer cache is a registry cache (`cache-from`/`cache-to` on a `:buildcache` tag in the same GHCR package), not `type=gha`: the Actions cache is one 10 GB per-repo LRU that the hot composer and `node_modules` entries would evict a ~1 GB image cache out of between deploys.

This exists because the build used to run *inside* the deploy job on the homelab runner, putting a five-stage `docker build` on the same four cores that serve live prod traffic. The secondary win is an offsite image history: the host only ever held `:latest`/`:previous` locally, so recovering further back than one deploy meant rebuilding from the commit.

> **Unverified in prod.** The GHCR split has never run against the real homelab host. The first deploy after it merges should be watched: GHCR package permissions, `ghcr.io` egress from the host, and the pull's effect on deploy wall-clock are all untested assumptions.

## How a deploy runs

The `deploy` job in [.github/workflows/ci.yml](.github/workflows/ci.yml) runs on the `[self-hosted, homelab]` runner, only on `push` to `main`, after `ci-gate` (lint + pest + vitest + secret-scan) **and** `build` pass. `concurrency: deploy-prod` with `cancel-in-progress: false` serializes deploys. In order:

1. Tag current `:latest` → `:previous` (rollback target).
2. Pull `ghcr.io/<owner>/<repo>/app:<git-sha>` (token widened to `packages: read`) and re-tag it locally as `temari/app:latest`; bring up mysql + redis with `--wait` (cold-start safe — a fresh box self-bootstraps). Every later step resolves the image through that local tag via the `x-app-image` anchor in [compose.prod.yaml](compose.prod.yaml), so nothing downstream is registry-aware.
3. Tag the new image with the git SHA.
4. **Backup** the app DB and the analytics schema to `/var/lib/temari-backups` (gzip, `pipefail`-guarded, tiny-dump check skipped only when the schema is genuinely empty).
5. **Quiesce** scheduler + horizon (SIGTERM, kept down) so no scheduled command/job is mid-run during the roll.
6. `migrate --force`, then `migrate --database=analytics --path=database/migrations/analytics --force` (one-shot `compose run --rm app`).
7. Roll `app horizon pulse` onto the new image (`up -d --no-deps`) — the recreate gives Horizon fresh workers, so no separate `horizon:terminate`.
8. `artisan optimize` (caches config inside the running container, where the real env is loaded).
9. Healthcheck `/up` (20× retry), smoke-test `/login`, then resume `scheduler`.
10. Prune SHA-tagged `temari/app` images that aren't `:latest`/`:previous`.

### Migrations must be expand/contract

Steps 5-7 above quiesce the queue but **not** the `app` server: the old Octane image keeps serving live requests through the `migrate --force` (step 6) and only rolls onto the new image at step 7. So for the migrate+roll window the **previous code runs against the new schema**. A destructive migration (drop/rename a column, tighten an enum, add a NOT NULL without a default) will 500 those in-flight requests against columns the old code still reads or writes.

Write schema changes as **expand/contract split across two deploys**:

1. **Expand** (deploy 1): add the new column/table/enum value; backfill; make new code write both old and new. Never remove or narrow anything the currently-live code depends on.
2. **Contract** (deploy 2, after deploy 1 is fully rolled): drop the now-unused old column / tighten the constraint, once no running code references it.

The heavier alternative is wrapping the migrate step in `artisan down` (a maintenance-mode blip on every deploy); expand/contract avoids that blip and keeps the still-live old code safe against the new schema, so prefer it. See the deploy order in [.github/workflows/ci.yml](.github/workflows/ci.yml).

Note what this does **not** buy: the roll itself is not zero-downtime. There is one `app` container on one loopback port and no second replica, and step 7 is a plain `up -d --no-deps app horizon pulse` ([.github/workflows/ci.yml:457](.github/workflows/ci.yml)) — compose stops the old container and starts the new one in place, so requests are refused for the second or two that takes. The `/up` healthcheck at step 9 polls the container that already replaced the old one; it verifies the new image booted, it does not gate a cutover. Expand/contract is what keeps that window a brief connection refusal instead of a wall of 500s.

## Rollback

A failed deploy **tries to roll itself back first**. The `Roll back on failure` step ([.github/workflows/ci.yml:498](.github/workflows/ci.yml)) runs under `if: failure()`: it restarts the quiesced scheduler/horizon/pulse, re-tags `:previous` → `:latest`, rolls the containers back and re-polls `/up`. `Alert on deploy failure` ([.github/workflows/ci.yml:523](.github/workflows/ci.yml)) then pushes a `deploy:alert` to Telegram either way. So a red deploy usually means prod is already back on the previous image — check the alert before intervening by hand.

**It refuses to auto-roll when a migration ran this deploy.** `Detect pending migrations` ([.github/workflows/ci.yml:437](.github/workflows/ci.yml)) runs `migrate:status --pending=1` on both connections before migrating and records `MIGRATIONS_APPLIED`. When that is `true` — including when it is *unset*, which it defaults to, so an early failure fails safe — the rollback step deliberately stops and prints a manual-recovery error instead. Re-tagging the image would put old code against a new schema, which is the one thing expand/contract cannot protect against if the migration was destructive. Recover with the `Rollback prod` workflow plus `./scripts/restore-db.sh <backup>`.

Neither path has ever fired in prod, so treat both as untested.

**Overruns reach that failure path by design.** Timeouts are set per *step* (the image pull, both dumps, both migrates, and the two failure-path steps) rather than only on the job, because a job-level timeout is a *cancellation* and GitHub skips every `if: failure()` step when one trips — an overrun would otherwise strand a half-deployed stack with no rollback, no alert and no summary. The job cap is a last-resort backstop sitting above the sum of the step caps. Every `curl` in the deploy and rollback workflows carries `--max-time` for the same reason: a worker that accepts a connection but never answers would otherwise hang a retry loop past the backstop.

### Manual rollback

Every successful deploy leaves `temari/app:previous` and `temari/app:<git-sha>` on the host. To roll back the most recent deploy by hand, re-tag `:previous` → `:latest`, `up -d --no-deps app horizon scheduler`, and `horizon:terminate`. The full commands and the `/opt/temari/.env` setup table live in the Deployment section of [README.md](README.md).

The `Rollback prod` workflow ([.github/workflows/rollback.yml](.github/workflows/rollback.yml)) is **deliberately registry-unaware**: it only inspects and re-tags local `temari/app` images. Building on a hosted runner does not change that, because the deploy still lands `temari/app:latest` as a local tag on the host and still tags the outgoing one `:previous` before it does. Keep it that way — a rollback that has to reach the network is a rollback that can fail when the network is why you're rolling back.

**On the host you can still only go back one deploy.** The prune step runs `if: always()` on every deploy and deletes every `temari/app` tag except `:latest`, `:previous` and the SHA just deployed ([.github/workflows/ci.yml:535](.github/workflows/ci.yml)), so the host holds exactly two recoverable images. What is new is that GHCR keeps a `:<git-sha>` per deploy, so recovering further back is now a `docker pull ghcr.io/<owner>/<repo>/app:<sha>` + local re-tag instead of a rebuild from source.
