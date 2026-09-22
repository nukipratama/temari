---
title: Maintenance mode
description: One durable app_config flag behind Laravel's maintenance driver, switched from Pulse or artisan down/up, that closes the app to everyone but admins and pauses the queue and scheduler
tags: [architecture, infra]
status: living
reviewed: 2026-09-21
code_refs:
  - app/Support/Config/AppConfigMaintenanceMode.php
  - app/Http/Middleware/EnforceMaintenanceMode.php
  - app/Livewire/Pulse/SystemControl.php
  - app/Http/Controllers/Auth/StravaAuthController.php
  - bootstrap/app.php
  - config/app.php
  - routes/console.php
  - resources/views/maintenance.blade.php
  - .github/workflows/ci.yml
---

# Maintenance mode

## One flag, every container

Laravel's maintenance driver is [AppConfigMaintenanceMode](app/Support/Config/AppConfigMaintenanceMode.php#L14), registered in [AppServiceProvider](app/Providers/AppServiceProvider.php) and pinned in [config/app.php](config/app.php). It stores `AppConfigKey::MaintenanceEnabled` in the `app_config` MySQL table, the same durable control plane as the AI and Strava kill-switches, so `app`, `horizon` and `scheduler` all read one value and a container recreate cannot lose it. The driver is hardcoded, not env-driven: the old default was `file`, which scoped `artisan down` to the one container that ran it.

[AppConfig](app/Support/Config/AppConfig.php) caches every control-plane key for 60 seconds in the application's Redis cache, which production points at the evictable `redis-cache` service. MySQL remains authoritative: a miss or eviction reads MySQL, while every successful MySQL write immediately writes the new value through to Redis so toggles cross container boundaries without waiting for expiry. The TTL bounds stale state if a cache write is missed.

Failure behavior is explicit. If Redis is unavailable, reads fall back to MySQL and writes still commit there. If MySQL is unavailable but Redis still has a last-known value, that cached value is served until it expires. An ordinary `AppConfig` read with neither store available throws; the maintenance driver alone catches that case, logs it and fails closed so an intentional maintenance window is never silently lifted.

`app()->isDownForMaintenance()` reads through this driver, so Laravel's own consumers honour it with no extra code:

- **Queue workers** pause before popping a job. Horizon passes `--force` only when a supervisor sets `force`, and none does. A paused worker sees `artisan up` through the write-through Redis value and resumes without needing its scoped container reset.
- **The scheduler** skips every task except `schedule:heartbeat` ([routes/console.php](routes/console.php#L33)), which keeps the scheduler container's healthcheck green.
- **`artisan down` / `artisan up`** flip the same flag as the Pulse toggle. Their `--secret`, `--render`, `--redirect` and `--retry` options are ignored.

`horizon:pause` is never used: `/up` checks Horizon's master supervisor, and a paused one fails the deep health check. Idle workers under a running master are the intended state.

## Who gets in

Laravel's global `PreventRequestsDuringMaintenance` is removed ([bootstrap/app.php](bootstrap/app.php)). It runs before the session starts, so it would 503 the admin on the Pulse page that switches maintenance off. [EnforceMaintenanceMode](app/Http/Middleware/EnforceMaintenanceMode.php) runs inside `web` instead. Login, the OAuth routes and the Strava webhook remain reachable; `/up` and `/ready` sit outside the web group. Everything else, including Telegram, the client-error sink, Pulse and devtools, first requires an authenticated `is_admin` user while maintenance is active. Devtools routes retain their existing password gate as a second check.

`/ready` is the shallow container probe: it proves Laravel booted and can dispatch a request without querying MySQL, Redis or Horizon. `/up` remains the separate whole-system probe through `VerifyDependencies`. Both sit outside `web`, and Caddy serves the PWA assets from disk, so readiness, dependency health and the deploy smoke test keep passing during maintenance. Everyone else gets [the maintenance page](resources/views/maintenance.blade.php) with a 503 and `Retry-After`. An open Inertia app gets a hard reload, so it lands on the page too.

The demo button hides and `/auth/demo` is refused. A new athlete finishing the Strava connect is [refused before any row is written](app/Http/Controllers/Auth/StravaAuthController.php#L174) and [deauthorized on Strava](app/Http/Controllers/Auth/StravaAuthController.php#L195), so they don't hold one of the app's athlete slots. Returning athletes sign in as usual and then see the page.

## What pausing costs

Scheduled tasks catch up only where the work has a durable recovery contract. A window across a task's slot can skip a run: `trend:snapshot-daily` queues closed-date recovery from its per-user cursor in bounded 365-day chunks, while `streak:settle` queues chronological per-user settlement from its streak cursor and keeps weekly recap creation gated until every real athlete is current. The hourly kickoff sweep also records today's deterministic readiness clamp when the 00:01 briefing was missed; it never reconstructs a past day's guidance. After the recovery migration first deploys, operators may run `./vendor/bin/sail artisan streak:settle` once with healthy workers to start the null-cursor backlog before the next Monday window. Running business work during maintenance would be worse, since activities still sitting in the paused queue would count as missing runs.

The deploy reads the flag before changing anything. With no pending migration it never touches maintenance. With a pending app or analytics migration it enables maintenance before either migrator runs, rolls and checks the release, starts scheduler and Pulse, then lifts only the flag it enabled itself. Owner-enabled maintenance is never lifted. A failure anywhere on the migration path keeps maintenance active because that path deliberately refuses automatic image rollback after schema work may have started.
