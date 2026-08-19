---
title: HR zones
description: Max/resting HR input, Karvonen-derived Z1–Z5 with manual per-zone overrides, source badges — inlined into Settings as an expand/collapse disclosure.
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

[HrZonesDisclosure](../../resources/js/components/settings/HrZonesDisclosure.tsx) opens on a trigger row (icon, "HR zones", and a collapsed line naming the current source — default / synced from Strava with a last-synced label / set manually) with a chevron that flips on toggle. Expanded, it's two stacked cards, ported verbatim from the retired page:

1. **Max & Resting HR** — two bpm inputs. An "Auto-calculate from Max & Resting" button recomputes the zones from these — the recalculation is button-gated, not live as you type.
2. **Your zones** — the Z1–Z5 breakdown, both the derived preview and the hand-tuning in one place. Zones are derived client-side by the exported `deriveZones(maxHr, restingHr)`, where each zone's `lo` is `round(resting + pct × (max − resting))` using the **Karvonen %HRR** breakpoints `[0.488, 0.664, 0.792, 0.904, 0.968]`; each `hi` is the next zone's `lo`, and Z5's `hi` is an open-ended sentinel (`999`, shown as `∞`). The breakpoints are mirrored from the server request so the preview matches the stored result byte for byte. Each zone's `lo`/`hi` stays individually editable via `BoundaryInput` fields — the rule (and the validation) is that each zone's upper bound must equal the next zone's lower bound, so there are no gaps.

Source `strava`/`manual` also show a "Resync from Strava" (scope-gated) and "Reset to default zones" action row above the two cards. Both mutate server-side, then reload just the `hrZones` Inertia prop (`router.reload({ only: ['hrZones'] })`) rather than the whole page — the disclosure and any unrelated in-progress state elsewhere on Settings (a toggled notification, say) survive the round-trip.

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
