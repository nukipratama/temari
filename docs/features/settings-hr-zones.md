---
title: HR zones
description: Max/resting HR input, Karvonen-derived Z1–Z5 with one editable bound per zone, source badges — inlined into Settings as an expand/collapse disclosure.
tags: [feature, settings]
status: living
reviewed: 2026-08-19
code_refs:
  - resources/js/components/settings/HrZonesDisclosure.tsx
  - resources/js/pages/Settings/Index.tsx
  - app/Http/Controllers/SettingsController.php
  - app/Http/Controllers/RunnerZonesController.php
  - app/Http/Requests/UpdateHrZonesRequest.php
  - app/Models/RunnerProfile.php
  - app/Models/User.php
  - routes/web.php
---

# HR zones

A runner's personal heart-rate zones, so every run is scored against *their* physiology rather than a default. Until they save, the app falls back to a standard profile. This used to be its own page (`/settings/zones`, GET); it's now an inline expand/collapse disclosure inside [[settings]], in the "Running" section.

**Navigation:** the disclosure lives on `/settings` directly — no route of its own to view it. The three mutation endpoints stay at `/settings/zones`, unchanged: `PATCH` (save), `DELETE` (reset to default), `POST /settings/zones/resync-strava` (pull from Strava). Named routes: `settings.zones.update`, `settings.zones.reset`, `settings.zones.resync`.

## System dependencies

- **Stream analysis** — zones are consumed by [[stream-analysis]] to compute time-in-zone per run.
- **Data model** — `RunnerProfile`, `User::hrProfile()` shapes in [[data-model]].

## The disclosure

[HrZonesDisclosure](../../resources/js/components/settings/HrZonesDisclosure.tsx) opens on a trigger row (a heart-pulse glyph, "Heart-rate zones", and a collapsed line naming the current source — default estimates / synced from Strava with a last-synced label / set manually) with a chevron that rotates on toggle. It is **closed by default**. Expanded, it is one panel in the prototype's order:

1. **Max and Resting HR** — two bpm inputs in a grid that goes two-up on the mobile column and four-up above 900px, so a three-digit field is not given a third of the row. An "Auto-calculate" button recomputes the bounds from these — button-gated, not live as you type.
2. **The five zone bounds** — one input per zone, its **lower** bound. Bounds are derived client-side by the exported `deriveBounds(maxHr, restingHr)` as `round(resting + pct × (max − resting))` using the **Karvonen %HRR** breakpoints `[0.488, 0.664, 0.792, 0.904, 0.968]`, mirrored from the server request so the preview matches the stored result byte for byte.

There is deliberately **no upper-bound field**. `UpdateHrZonesRequest` rejects any submission where a zone's `hi` is not the next zone's `lo`, so every upper bound is already determined by the five lower ones; the exported `toZonePairs()` widens them back into the `{lo, hi}` payload on submit, with Z5's `hi` fixed at the open-ended sentinel (`999`). The gap/overlap error the old paired inputs could produce is therefore unreachable rather than merely validated, and a server complaint on `zones.N.hi` is surfaced against zone **N+1**'s field, the only one the user can reach.

Source `strava`/`manual` also show a "Reset to default" action beside Save, and `manual` a scope-gated "Resync from Strava" beneath it. Both mutate server-side, then reload just the `hrZones` Inertia prop (`router.reload({ only: ['hrZones'] })`) rather than the whole page — the disclosure and any unrelated in-progress state elsewhere on Settings (a toggled notification, say) survive the round-trip.

Submit posts `router.patch('/settings/zones', …)` with `max_hr`, `resting_hr` and the five `{lo, hi}` zones — same payload shape as before.

## Server side

[SettingsController](../../app/Http/Controllers/SettingsController.php)`::resolveHrZones()` builds the `hrZones` prop (`profile` from `User::hrProfile()`, `source`, `stravaSyncedLabel`, `canSyncFromStrava`) alongside Settings' other props — this replaced [RunnerZonesController](../../app/Http/Controllers/RunnerZonesController.php)'s retired `index()`.

`RunnerZonesController` still owns the three mutations, unchanged:

- `update()` validates through [UpdateHrZonesRequest](../../app/Http/Requests/UpdateHrZonesRequest.php), re-keys the submitted zones to `Z1`–`Z5`, and `updateOrCreate`s the [RunnerProfile](../../app/Models/RunnerProfile.php) row. The model's `saving` hook stamps `hr_zones_changed_at` whenever max/resting/zones change, and a `saved` hook busts the cached Inertia marker.
- `resetToDefault()` deletes the profile row, falling back to config defaults.
- `resyncFromStrava()` runs `SyncZonesJob::dispatchSync(..., force: true)` inline (not queued), scope-gated on `profile:read_all`.

## Profile shape & optimal cadence

`User::hrProfile()` in [User.php](../../app/Models/User.php) returns `max_hr`, `resting_hr`, `hr_zones` **and** `optimal_cadence_spm`. When no custom `RunnerProfile` exists, it serves config defaults (including `config('runner.optimal_cadence_spm')`). Note: optimal cadence is part of the stored/served profile but is **not** an editable field in this disclosure — it is surfaced in run analysis, not tuned here.

## See also

[[settings]] · [[data-model]] · [[run-detail]]
