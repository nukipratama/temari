---
title: intervals.icu connection
description: Connecting an athlete's intervals.icu account from Settings → Integrations, and keeping today's planned run on their intervals.icu calendar so it reaches their watch.
tags: [feature, integrations]
status: living
reviewed: 2026-10-20
code_refs:
  - app/Http/Controllers/IntervalsIcu/IntervalsIcuConnectionController.php
  - app/Http/Requests/StoreIntervalsIcuConnectionRequest.php
  - app/Models/IntervalsIcuConnection.php
  - app/Services/IntervalsIcu/IntervalsIcuClient.php
  - resources/js/components/settings/IntervalsIcuPanel.tsx
  - resources/js/components/settings/IntervalsIcuConnectForm.tsx
  - app/Services/IntervalsIcu/IntervalsIcuWorkoutMapper.php
  - app/Services/IntervalsIcu/IntervalsIcuSync.php
  - app/Jobs/IntervalsIcu/SyncIntervalsIcuWorkoutJob.php
  - app/Console/Commands/IntervalsIcu/SyncCommand.php
  - resources/js/components/home/IntervalsIcuStatus.tsx
  - app/Livewire/Pulse/IntervalsIcuHealth.php
---

# intervals.icu connection

Huawei offers third parties no way to put a workout on a watch, but intervals.icu forwards the next planned run on its calendar to HUAWEI Health (and Garmin, COROS and others) through its own watch connections. temari writes today's run to the athlete's intervals.icu calendar, and the athlete taps once in their watch app to send the run to the watch.

## Connecting

The athlete pastes their intervals.icu **athlete ID** and **API key** (intervals.icu → Settings → Developer settings) into the intervals.icu row under Settings → **Integrations**, an inline disclosure like [[settings-hr-zones]]. No OAuth app approval is involved: intervals.icu's personal-key API takes Basic auth with the literal username `API_KEY` and the key as the password (`IntervalsIcuClient::verify`).

`IntervalsIcuConnectionController::store` checks the pair against `GET /athlete/{id}` before saving anything. `StoreIntervalsIcuConnectionRequest` accepts the ID with or without its leading `i`. A 401/403/404 means the ID and key don't match; anything else (a 5xx, a timeout) means intervals.icu is unreachable, and the form says so rather than blaming the key (`IntervalsIcuVerification`). Saving again replaces the key in place and clears a broken state.

## Storage and secrecy

One row per user in `intervals_icu_connections` (`IntervalsIcuConnection`), with the key encrypted at rest and `#[Hidden]`. Settings receives the athlete ID and the connected/broken flags only (`SettingsController::resolveIntervalsIcu`), never the key. `api_key` is on the exception handler's `dontFlash` list in `bootstrap/app.php`, so a rejected key is never written back into the session as old input. The row is deleted with the account by its foreign key.

## States

- **not connected**: the row folds open to the form.
- **connected**: a static row showing the athlete ID, with a demoted disconnect beneath it, like Telegram in [[settings]].
- **broken** (`broken_at` set): the row says the key stopped working and folds open to the same form with the ID prefilled. The sync sets it on a 401/403 (see below).

Disconnecting asks first in a `TemariNudgeModal`, then removes today's temari workout from the calendar, then deletes the row; if intervals.icu can't be reached, the row goes anyway, the failure is logged and the workout stays. Connecting a different athlete ID, and deleting the account through [UserEraser](app/Services/User/UserEraser.php), remove today's workout the same way, before the old credentials are replaced or deleted; reconnecting the same athlete leaves it alone. The demo account can't connect or disconnect: both routes carry `block-demo-writes`, and the row opens the demo notice instead of the form.

## The workout sent

[IntervalsIcuWorkoutMapper::map()](app/Services/IntervalsIcu/IntervalsIcuWorkoutMapper.php) turns a day exactly as Plan and Today show it (`PlanRenderer::dayPayload()`) into an intervals.icu workout: a name such as "Easy 8 km", "Long run 14 km", "Tempo 3×8 min", "Intervals 5×3 min", "Race 10K" or "Time trial 3K", and a description that opens with the day's purpose line (`SessionPurpose::of()`, the same line Plan and Today show), then `IntervalsIcuWorkoutMapper::OWNER` ("from temari · changes with your plan"), then the step lines intervals.icu parses. Huawei Health shows the two plain lines above the steps; there is no way to hide them.

- **Targets.** A step runs to its `pace_range` (see [[plan-periodizer]]), since the watch alerts outside the band and a single pace becomes a zero-width band. A step without one (an athlete with no VDOT yet) gets its temari heart-rate zone as a share of the athlete's intervals.icu threshold heart rate (`% LTHR`). Without that threshold the day is not sent (`needsHeartRate()`). A step without a target would be dropped by the watch export. A workout keeps one target kind: when easy steps carried heart rate and the work carried pace, Huawei dropped every pace step, so easy running stays on pace and the athlete's own watch alert covers heart rate (owner decision, 2026-10-17).
- **Lengths.** Easy running and race efforts run to a distance, always written in `km` because intervals.icu reads `m` as minutes; warm-ups, jogs and quality work run to time. The open-ended "easy for the rest" step has no length and is left out. For an athlete without paces, the easy running around a long run's harder block is open-ended too, so only the block is sent (owner decision, 2026-10-17).
- **Step types.** `warmup` → warm-up, `recovery` → recovery (Huawei labels it Walk but keeps the pace), everything else → active. On a long run with a harder block, the easy running before it is warm-up and after it cool-down. `intensity=rest` is never used, since Huawei drops it.
- **Repeats.** Identical rep and recovery pairs become one `Nx` block. temari has no jog after the last rep; the repeat adds one. A last rep at a different pace stays outside the block.

Checked live on 2026-10-17: all nine shapes parsed into the intended steps, ranges, repeats and `% LTHR` targets in intervals.icu, and synced to Huawei Health with every step, target and repeat intact.

## The sync

Only today's run is ever sent: Huawei Health shows just the next upcoming workout, so a week of them would add nothing on the watch and leave six to go stale.

Every 15 minutes `intervals-icu:sync` queues one `SyncIntervalsIcuWorkoutJob` per non-demo athlete with a working connection, and connecting queues one straight away. The job runs `IntervalsIcuSync::sync()`, which builds today exactly as Today shows it (`CurrentWeekPlanBuilder`), maps it, and hashes the workout's name and description:

- **first send of the day**: create the event and keep its id (`event_id`, `synced_on`, `payload_hash` on the connection row). The create goes through `POST /events/bulk?upsert=true` with an `external_id` fixed per athlete and day, so a retry after a create that landed but timed out updates that event rather than adding a second;
- **hash changed** (a clamp, a regenerated plan, a skipped rep): `PUT` the same event in place. If the athlete deleted it, a new one is created;
- **unchanged**: no call at all, except that a day without paces reads the athlete's run threshold heart rate each time, so a threshold they change on intervals.icu is used on the next tick;
- **today becomes a rest day, is skipped, or can't be sent**: the event is deleted. The watch may still hold a course it already received; that is accepted.
- **today's run is credited** (done, partial or overreached): the event is left exactly as sent, with no update or delete, and nothing is sent if there was no event yet. Once run, the watch workout is history.

Measured against a throwaway account on 2026-10-20 by posting the same workout twice per candidate create path: only the bulk create with `upsert=true` and an `external_id` kept one event and one id, and it works with a personal API key. A plain `POST /events` adds a second event whatever it carries: intervals.icu replaces a client `uid` with its own UUID, so `upsertOnUid` matches only a uid it issued, which a timed-out create never returns. The OpenAPI document lists `uid` and `upsertOnUid` as if they deduplicated, so measure against the live API before relying on any other create path.

Workouts from earlier days stay on the calendar, and temari never touches events the athlete made. A 401/403 marks the connection broken (`broken_at`), stops its syncs, and sends `IntervalsIcuDisconnectedNotification` once; reconnecting clears it. Any other failure sets the day's status to unreachable and the next tick tries again.

`sync_status` (`IntervalsIcuSyncStatus`) drives the chip beside the session on Today (`IntervalsIcuStatus.tsx`): green "on intervals.icu" when sent, and a warning "not sent" with a reason line when there is no threshold heart rate, intervals.icu is unreachable, or the key is broken. Rest days show no chip. The `IntervalsIcuHealth` Pulse card counts working and broken connections, today's statuses, and the sends, updates, removals, failures and broken keys recorded under `intervals_icu_sync`.

## Privacy

The privacy policy (`LegalDocuments::privacy`) lists intervals.icu as a recipient of today's planned run, only if the athlete connects it, and lists the stored athlete ID and encrypted key. The Settings data-use section adds `DataUseStatement::INTERVALS_ICU` only while a connection exists.

## See also

[[settings]] · [[strava-connect]]
