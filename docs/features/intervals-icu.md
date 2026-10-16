---
title: intervals.icu connection
description: Connecting an athlete's intervals.icu account from Settings → Integrations, so their planned runs can reach their watch.
tags: [feature, integrations]
status: living
reviewed: 2026-10-16
code_refs:
  - app/Http/Controllers/IntervalsIcu/IntervalsIcuConnectionController.php
  - app/Http/Requests/StoreIntervalsIcuConnectionRequest.php
  - app/Models/IntervalsIcuConnection.php
  - app/Services/IntervalsIcu/IntervalsIcuClient.php
  - resources/js/components/settings/IntervalsIcuPanel.tsx
  - resources/js/components/settings/IntervalsIcuConnectForm.tsx
  - app/Services/IntervalsIcu/IntervalsIcuWorkoutMapper.php
---

# intervals.icu connection

Huawei offers third parties no way to put a workout on a watch, but intervals.icu forwards the next planned run on its calendar to HUAWEI Health (and Garmin, COROS and others) through its own watch connections. temari writes the plan to the athlete's intervals.icu calendar, and the athlete taps once in their watch app to send the run to the watch. Scope, rules and the proof of concept are in issue #2155.

## Connecting

The athlete pastes their intervals.icu **athlete ID** and **API key** (intervals.icu → Settings → Developer settings) into the intervals.icu row under Settings → **Integrations**, an inline disclosure like [[settings-hr-zones]]. No OAuth app approval is involved: intervals.icu's personal-key API takes Basic auth with the literal username `API_KEY` and the key as the password (`IntervalsIcuClient::verify`).

`IntervalsIcuConnectionController::store` checks the pair against `GET /athlete/{id}` before saving anything. `StoreIntervalsIcuConnectionRequest` accepts the ID with or without its leading `i`. A 401/403/404 means the ID and key don't match; anything else (a 5xx, a timeout) means intervals.icu is unreachable, and the form says so rather than blaming the key (`IntervalsIcuVerification`). Saving again replaces the key in place and clears a broken state.

## Storage and secrecy

One row per user in `intervals_icu_connections` (`IntervalsIcuConnection`), with the key encrypted at rest and `#[Hidden]`. Settings receives the athlete ID and the connected/broken flags only (`SettingsController::resolveIntervalsIcu`), never the key. `api_key` is on the exception handler's `dontFlash` list in `bootstrap/app.php`, so a rejected key is never written back into the session as old input. The row is deleted with the account by its foreign key.

## States

- **not connected**: the row folds open to the form.
- **connected**: a static row showing the athlete ID, with a demoted disconnect beneath it, like Telegram in [[settings]].
- **broken** (`broken_at` set): the row says the key stopped working and folds open to the same form with the ID prefilled. Nothing sets this state yet; the sync job will set it on a 401/403.

Disconnecting deletes the row. The demo account can't connect or disconnect: both routes carry `block-demo-telegram`, and the row opens the demo notice instead of the form.

## The workout sent

[IntervalsIcuWorkoutMapper::map()](app/Services/IntervalsIcu/IntervalsIcuWorkoutMapper.php) turns a day exactly as Plan and Today show it (`PlanRenderer::dayPayload()`) into an intervals.icu workout: a name such as "Easy 8 km", "Long run 14 km", "Tempo 3×8 min", "Intervals 5×3 min", "Race 10K" or "Time trial 3K", and a description holding one plain line (`IntervalsIcuWorkoutMapper::SUMMARY`) and then the step lines intervals.icu parses. Huawei Health shows that description above the steps; there is no way to hide it.

- **Targets.** A step runs to its `pace_range` (see [[plan-periodizer]]), since the watch alerts outside the band and a single pace becomes a zero-width band. A step without one (an athlete with no VDOT yet) gets its temari heart-rate zone as a share of the athlete's intervals.icu threshold heart rate (`% LTHR`). Without that threshold the day is not sent (`needsHeartRate()`). A step without a target would be dropped by the watch export.
- **Lengths.** Easy running and race efforts run to a distance, always written in `km` because intervals.icu reads `m` as minutes; warm-ups, jogs and quality work run to time. The open-ended "easy for the rest" step has no length and is left out. For an athlete without paces, the easy running around a long run's harder block is open-ended too, so only the block is sent (owner decision, 2026-10-17).
- **Step types.** `warmup` → warm-up, `recovery` → recovery (Huawei labels it Walk but keeps the pace), everything else → active. On a long run with a harder block, the easy running before it is warm-up and after it cool-down. `intensity=rest` is never used, since Huawei drops it.
- **Repeats.** Identical rep and recovery pairs become one `Nx` block. temari has no jog after the last rep; the repeat adds one. A last rep at a different pace stays outside the block.

Checked live on 2026-10-17: all nine shapes parsed into the intended steps, ranges, repeats and `% LTHR` targets.

## Privacy

The privacy policy (`LegalDocuments::privacy`) lists intervals.icu as a recipient of the coming week's planned runs, only if the athlete connects it, and lists the stored athlete ID and encrypted key. The Settings data-use section adds `DataUseStatement::INTERVALS_ICU` only while a connection exists.

## See also

[[settings]] · [[strava-connect]]
