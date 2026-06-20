---
title: Deployment & runtime
description: Multi-stage FrankenPHP/Octane image, the loopback-only prod compose stack behind a Cloudflare Tunnel, Redis DB partitioning, and the GitHub Actions build/migrate/roll/rollback flow on a single homelab host
tags: [architecture, infra]
status: living
reviewed: 2026-06-20
code_refs:
  - Dockerfile
  - compose.prod.yaml
  - docker/Caddyfile
  - .github/workflows/ci.yml
  - config/octane.php
  - config/database.php
  - README.md
---

# Deployment & runtime

How TemanLari is built into an image, run as a compose stack, and continuously deployed to **one self-hosted homelab host** on every push to `main`. The host sits behind an existing Cloudflare Tunnel; nothing in this repo provisions the tunnel itself. Start here before touching the [Dockerfile](Dockerfile), [compose.prod.yaml](compose.prod.yaml), or the deploy job in [.github/workflows/ci.yml](.github/workflows/ci.yml).

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

[docker/Caddyfile](docker/Caddyfile) handles static caching (`/build/*` immutable, favicons 7d) and an **edge basic-auth** wall on `/horizon*`, `/pulse*`, `/ai-usage*` (the `@devtools` `handle` block, credentials from `DEVTOOLS_BASIC_AUTH_*` in the host `.env`). Basicauth lives in a `handle` block on purpose: as a free-standing directive the worker chain bypassed Caddy ordering and silently skipped auth. `trusted_proxies static private_ranges` is set, but the app trusts proxies via `bootstrap/app.php` `trustProxies(at: '*')` — see [[trust-all-proxies-cloudflare]].

## The prod stack

[compose.prod.yaml](compose.prod.yaml) (project `teman-lari-prod`) runs six services, all sharing the `*app-image` and the `*app-env` anchor; secrets load from `/opt/teman-lari/.env` on the host via `env_file:` (nothing flows through GitHub Actions secrets):

- **`app`** — the FrankenPHP server. The **only** service with a host port, and it's **loopback-only** `127.0.0.1:7001:7001`; cloudflared on the host reaches it there. HTTP `/up` healthcheck.
- **`horizon`** — `php artisan horizon` queue worker, `stop_grace_period: 60s` for graceful drain.
- **`scheduler`** — `php artisan schedule:work`.
- **`pulse`** — combined daemon: `pulse:check` (Servers recorder, host root bind-mounted read-only at `/host`) + `pulse:work` (ingest drain), where either child dying exits the wrapper so Docker restarts it.
- **`mysql`** — custom `teman-lari/mysql:8.4` (stock + initdb bootstrap) on a persistent `mysql_data` volume, tuned via command flags (`innodb-buffer-pool-size=1536M`, `max-connections=40`, `skip-name-resolve`). Stays on the internal network only.
- **`redis`** — `redis:8-alpine`, AOF `everysec`, `maxmemory 512mb` / `noeviction`, persistent `redis_data` volume. The healthcheck is a **write probe** (`SET`), not `ping`, because Redis answers PONG while still replaying AOF but rejects writes — a ping would let app/horizon connect mid-replay and read empty sessions.

`app`/`horizon`/`scheduler`/`pulse` all `depends_on` mysql + redis `service_healthy`, and carry per-service `deploy.resources` limits with CPU floors that sum well under the shared 4-core host.

### Redis DB partitioning

One Redis instance, separated by DB number ([config/database.php](config/database.php) `redis` block + the env in [compose.prod.yaml](compose.prod.yaml)):

| DB | Connection | Holds |
| --- | --- | --- |
| 0 | `default` | queue jobs + Horizon state + sessions (`SESSION_CONNECTION=default`) |
| 1 | `cache` | application cache (`REDIS_CACHE_DB=1`) |
| 2 | `pulse` | Pulse ingest buffer (`PULSE_REDIS_DB=2`) |

Session cookie name and the Redis/cache key prefixes are pinned to **fixed literals** (`SESSION_COOKIE`, `REDIS_PREFIX`, `CACHE_PREFIX`) instead of being derived from `APP_NAME`, so a cosmetic name/tagline edit can't rename the cookie or shift every key prefix and log everyone out. See [[fixed-session-cookie]].

## How a deploy runs

The `deploy` job in [.github/workflows/ci.yml](.github/workflows/ci.yml) runs on the `[self-hosted, homelab]` runner, only on `push` to `main`, after `ci-gate` (lint + pest + vitest + secret-scan) passes. `concurrency: deploy-prod` with `cancel-in-progress: false` serializes deploys. In order:

1. Tag current `:latest` → `:previous` (rollback target).
2. `compose build app`; bring up mysql + redis with `--wait` (cold-start safe — a fresh box self-bootstraps).
3. Tag the new image with the git SHA.
4. **Backup** the app DB and the analytics schema to `/var/lib/teman-lari-backups` (gzip, `pipefail`-guarded, tiny-dump check skipped only when the schema is genuinely empty).
5. **Quiesce** scheduler + horizon (SIGTERM, kept down) so no scheduled command/job is mid-run during the roll.
6. `migrate --force`, then `migrate --database=analytics --path=database/migrations/analytics --force` (one-shot `compose run --rm app`).
7. Roll `app horizon pulse` onto the new image (`up -d --no-deps`) — the recreate gives Horizon fresh workers, so no separate `horizon:terminate`.
8. `artisan optimize` (caches config inside the running container, where the real env is loaded).
9. Healthcheck `/up` (20× retry), smoke-test `/login`, then resume `scheduler`.
10. Prune SHA-tagged `teman-lari/app` images that aren't `:latest`/`:previous`.

## Rollback

Every successful deploy leaves `teman-lari/app:previous` and `teman-lari/app:<git-sha>` on the host. To roll back the most recent deploy, re-tag `:previous` → `:latest`, `up -d --no-deps app horizon scheduler`, and `horizon:terminate`. For an older commit, re-tag the SHA you want (within retention). The full commands and the `/opt/teman-lari/.env` setup table live in the Deployment section of [README.md](README.md).
