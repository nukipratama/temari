---
title: Streak reminders
description: Saturday-evening nudges for users whose weekly running streak is at risk — channel-neutral, idempotent, per-week gated, opt-in.
tags: [feature, notifications]
status: living
reviewed: 2026-07-20
code_refs:
  - app/Console/Commands/Gamification/StreakRemindCommand.php
  - app/Notifications/StreakReminderNotification.php
  - app/Models/WeeklySnapshot.php
  - app/Models/NotificationPreference.php
  - app/Models/TelegramConnection.php
  - database/migrations/2026_07_04_000001_create_streak_reminders_table.php
  - routes/console.php
---

# Streak reminders

Every Saturday at 18:00, `streak:remind` checks every user Temari can reach on an **outbound** channel (Telegram or web push) who hasn't turned notifications off. The nudge is time-boxed to the rest of the week, so it deliberately does not go to someone reachable only in the in-app inbox, who would read it days late (see [[inbox-is-an-always-on-channel]]). If the user has a live streak but hasn't run yet this week, it dispatches a nudge: one message per user, per at-risk week, no repeats.

There is no dedicated streak toggle — the nudge is governed by the single `notifications_enabled` master switch ([NotificationPreference](../../app/Models/NotificationPreference.php)), the same one that gates the post-run story and both recaps. It used to ride on `weekly_recap`, a coupling the UI never named; the master switch's own description names the streak nudge out loud, so the behaviour is chosen rather than inherited. A missing preference row means all-on.

## Flow

1. **Cron** (Saturday 18:00, see [routes/console.php](routes/console.php#L114)) fires `StreakRemindCommand::handle()`.
2. The command queries **users** who are not demo, haven't set `notifications_enabled = false`, and are reachable on at least one channel (an active Telegram connection **or** at least one web-push subscription), then applies three guards:
   - Skip if `WeeklySnapshot::consecutiveWeekStreak($userId)` returns `< 1` (no live streak).
   - Skip if the current week's `WeeklySnapshot` already has `runs > 0`.
   - Skip if `claim()` fails — `insertOrIgnore` on `streak_reminders` with a unique `(user_id, week_ending)` constraint, so repeated cron runs never double-send.
3. Sends `StreakReminderNotification($streakWeeks)` to the user via `$user->notify()`.

> The command iterates **users, not Telegram connections**. Iterating connections (as it did before web push existed) silently excluded anyone who only had phone push enabled, so they got weekly recaps but never a streak nudge.

The notification's `via()` re-checks the master switch at send time (the command already did, but `via()` runs again per notifiable) and fans out to the in-app inbox ([InAppChannel](../../app/Notifications/Channels/InAppChannel.php), always) plus whichever outbound channels are live: [TelegramChannel](../../app/Notifications/Channels/TelegramChannel.php) and/or [IdempotentWebPushChannel](../../app/Notifications/Channels/IdempotentWebPushChannel.php). The outbound pair carry the same title → body pair, with web push at `Urgency: high` (the nudge is time-boxed to the rest of the week, so an OS deferral under Low Power Mode would defeat it):

> 🔥 Streak lari {n} minggu kamu lagi di ujung
>
> Minggu ini belum ada progres. Sempatkan lari sebelum minggu ini berakhir, biar streak-nya nggak putus.

The tap-through points at the dashboard. See [[telegram-notifications]] for the broader notification pipeline.

## Idempotency

- `streak_reminders` table has a unique `(user_id, week_ending)` constraint — the same user in the same at-risk week can only receive one reminder, even if the command runs multiple times or the cron host restarts mid-iteration. A user wired on both outbound channels is still one claim, one notification (fanned out to the inbox and both of them).
- The notification's `via()` re-checks channel status and opt-in at send time, so a user who disconnects between dispatch and execution is never pestered.

## Schedule rationale

Saturday 18:00 gives the user a ~30-hour window (Saturday evening through Sunday midnight) to save their streak before the week closes. Earlier in the week would be premature (they might run Tuesday-Thursday); Sunday would be too late.

## Storage

| Table | Purpose |
|---|---|
| `streak_reminders` | Idempotency ledger: `(user_id, week_ending)` unique pair, no Eloquent model, accessed via `DB::table()` |

## Key dependencies

- `WeeklySnapshot::consecutiveWeekStreak()` — walks backward through contiguous running weeks; returns 0 if the most recent run is older than last Sunday. A week a **rest token** was spent on is bridged rather than counted, so it neither breaks the run of weeks nor adds to it (see [[gamification]]). The nudge still fires for a user holding tokens: spending one is a fallback for a week they could not run, not a reason to skip warning them while they still can.
- `NotificationPreference` — the channel-neutral master switch; the streak nudge reads `notifications_enabled`.
- `TelegramConnection` — one of the two reachable channels; `isRevoked()` checks for a null `revoked_at`.

## See also

[[telegram-notifications]] · [[telegram-account-linking]] · [[recaps]]
