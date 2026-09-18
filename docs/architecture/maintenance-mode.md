---
title: Maintenance mode
description: One durable app_config flag behind Laravel's maintenance driver, switched from Pulse or artisan down/up, that closes the app to everyone but admins and pauses the queue and scheduler
tags: [architecture, infra]
status: living
reviewed: 2026-09-19
code_refs:
  - app/Support/Config/AppConfigMaintenanceMode.php
  - app/Http/Middleware/EnforceMaintenanceMode.php
  - app/Livewire/Pulse/SystemControl.php
  - app/Http/Controllers/Auth/StravaAuthController.php
  - bootstrap/app.php
  - config/app.php
  - routes/console.php
  - resources/views/maintenance.blade.php
---

# Maintenance mode

## One flag, every container

Laravel's maintenance driver is [AppConfigMaintenanceMode](app/Support/Config/AppConfigMaintenanceMode.php#L14), registered in [AppServiceProvider](app/Providers/AppServiceProvider.php#L67) and pinned in [config/app.php](config/app.php#L121). It stores `AppConfigKey::MaintenanceEnabled` in the `app_config` MySQL table, the same durable control plane as the AI and Strava kill-switches, so `app`, `horizon` and `scheduler` all read one value and a container recreate cannot lose it. The driver is hardcoded, not env-driven: the old default was `file`, which scoped `artisan down` to the one container that ran it.

`app()->isDownForMaintenance()` reads through this driver, so Laravel's own consumers honour it with no extra code:

- **Queue workers** pause before popping a job. Horizon passes `--force` only when a supervisor sets `force`, and none does. The driver bypasses `AppConfig`'s memo in [active()](app/Support/Config/AppConfigMaintenanceMode.php#L29) because a paused worker never flushes scoped instances.
- **The scheduler** skips every task except `schedule:heartbeat` ([routes/console.php](routes/console.php#L33)), which keeps the scheduler container's healthcheck green.
- **`artisan down` / `artisan up`** flip the same flag as the Pulse toggle. Their `--secret`, `--render`, `--redirect` and `--retry` options are ignored.

`horizon:pause` is never used: `/up` checks Horizon's master supervisor, and a paused one fails the health check. Idle workers under a running master are the intended state.

## Who gets in

Laravel's global `PreventRequestsDuringMaintenance` is removed ([bootstrap/app.php](bootstrap/app.php#L45)). It runs before the session starts, so it would 503 the admin on the Pulse page that switches maintenance off. [EnforceMaintenanceMode](app/Http/Middleware/EnforceMaintenanceMode.php) runs inside `web` instead and checks the [named routes](app/Http/Middleware/EnforceMaintenanceMode.php#L33) for sign-in, the Strava and Telegram webhooks (which only enqueue) and the client-error sink, plus every [devtools-gated route](app/Http/Middleware/EnforceMaintenanceMode.php#L71) (Pulse, Horizon, the Livewire update endpoint, `/devtools`, which keeps its own password gate) **before** it reads the flag — an exempt request returns early with zero queries, so these endpoints stay reachable even when the `app_config` table or the DB itself is the thing that's down. A signed-in `is_admin` user gets through everything else too.

If reading the flag itself throws (`QueryException`/`PDOException` — a missing table, an unreachable DB), [AppConfigMaintenanceMode::active()](app/Support/Config/AppConfigMaintenanceMode.php#L29) treats maintenance as off and logs a `maintenance.flag_unreadable` warning rather than 503ing the whole app: a missing table or a down DB can't mean the owner switched maintenance on, and everything that needs the DB fails on its own anyway. This is the one place the flag is read, so web, queue workers and the scheduler all fail open the same way.

`/up` sits outside `web`, and Caddy serves the PWA assets from disk, so the deploy smoke test and healthcheck keep passing. Everyone else gets [the maintenance page](resources/views/maintenance.blade.php) with a 503 and `Retry-After`. An open Inertia app gets a hard reload, so it lands on the page too.

The demo button hides and `/auth/demo` is refused. A new athlete finishing the Strava connect is [refused before any row is written](app/Http/Controllers/Auth/StravaAuthController.php#L174) and [deauthorized on Strava](app/Http/Controllers/Auth/StravaAuthController.php#L195), so they don't hold one of the app's athlete slots. Returning athletes sign in as usual and then see the page.

## What pausing costs

Scheduled tasks don't catch up once maintenance lifts. A window across a task's slot skips that run: `trend:snapshot-daily` leaves a day with no row, and `streak:settle` skips settling a week that closes during the window. Running them during maintenance would be worse, since activities still sitting in the paused queue would count as missing runs.
