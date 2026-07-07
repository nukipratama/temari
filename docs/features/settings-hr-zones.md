---
title: HR zones settings (Zona HR)
description: The heart-rate-zone settings page — max/resting HR input, Karvonen-derived Z1–Z5, a live preview, manual overrides and save.
tags: [feature, settings]
status: living
reviewed: 2026-06-20
code_refs:
  - resources/js/pages/Pengaturan/ZonaHR.tsx
  - app/Http/Controllers/RunnerZonesController.php
  - app/Http/Requests/UpdateHrZonesRequest.php
  - app/Models/RunnerProfile.php
  - app/Models/User.php
  - routes/web.php
---

# HR zones settings (Zona HR)

`/pengaturan/zona` lets a runner set their personal heart-rate zones so every run is scored against *their* physiology, not a default. Until they save, the app falls back to a standard profile.

**Navigation:** `route('pengaturan.zona')` → `/pengaturan/zona` (GET); PATCH to same route saves. Named route: `pengaturan.zona`.

## System dependencies

- **Stream analysis** — zones are consumed by [[stream-analysis]] to compute time-in-zone per run.
- **Data model** — `RunnerProfile`, `User::hrProfile()` shapes in [[data-model]].

## The page

[ZonaHR.tsx](../../resources/js/pages/Pengaturan/ZonaHR.tsx) is split into three stacked sections:

1. **Max & Resting HR** — two `NumberField` bpm inputs. A "Hitung otomatis dari Max & Resting" button recomputes the zones from these.
2. **Preview zona (otomatis)** — a live, read-only Z1–Z5 breakdown that updates as you type. Zones are derived client-side by the exported `deriveZones(maxHr, restingHr)`: each zone's `lo` is `round(resting + pct × (max − resting))` using the **Karvonen %HRR** breakpoints `[0.488, 0.664, 0.792, 0.904, 0.968]`; each `hi` is the next zone's `lo`, and Z5's `hi` is an open-ended sentinel (`999`, shown as `Z5+`). The breakpoints are mirrored from the server request so the preview matches the stored result byte for byte.
3. **Atur manual (opsional)** — `BoundaryInput` fields to hand-tune each band. The rule (and the validation): each zone's upper bound must equal the next zone's lower bound so there are no gaps.

Submit `router.patch('/pengaturan/zona', …)` with `max_hr`, `resting_hr` and the five `{lo, hi}` zones.

## Server side

Both routes (`pengaturan.zona` GET, `pengaturan.zona.update` PATCH) live in [web.php](../../routes/web.php) behind auth.

[RunnerZonesController](../../app/Http/Controllers/RunnerZonesController.php):

- `index()` renders the page with `profile` (from `User::hrProfile()`) and `hasCustomProfile` (whether a `RunnerProfile` row exists yet).
- `update()` validates through [UpdateHrZonesRequest](../../app/Http/Requests/UpdateHrZonesRequest.php), re-keys the submitted zones to `Z1`–`Z5`, and `updateOrCreate`s the [RunnerProfile](../../app/Models/RunnerProfile.php) row. The model's `saving` hook stamps `hr_zones_changed_at` whenever max/resting/zones change, and a `saved` hook busts the cached Inertia marker.

## Profile shape & optimal cadence

`User::hrProfile()` in [User.php](../../app/Models/User.php) returns `max_hr`, `resting_hr`, `hr_zones` **and** `optimal_cadence_spm`. When no custom `RunnerProfile` exists, it serves config defaults (including `config('runner.optimal_cadence_spm')`). Note: optimal cadence is part of the stored/served profile but is **not** an editable field on this page — it is surfaced in run analysis, not tuned here.

## See also

[[data-model]] · [[run-detail]]
