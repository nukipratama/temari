---
title: Streak reminders (Telegram)
description: Saturday-evening Telegram nudges for users whose weekly running streak is at risk — idempotent, per-week gated, opt-in.
tags: [feature, notifications]
status: living
reviewed: 2026-07-07
code_refs:
  - app/Console/Commands/Gamification/StreakRemindCommand.php
  - app/Jobs/Telegram/SendStreakReminderJob.php
  - app/Models/WeeklySnapshot.php
  - app/Models/TelegramConnection.php
  - database/migrations/2026_07_04_000001_create_streak_reminders_table.php
  - routes/console.php
---

# Streak reminders (Telegram)

Every Saturday at 18:00, `streak:remind` checks every user with an active Telegram connection and `notify_weekly_recap = true`. If the user has a live streak but hasn't run yet this week, it dispatches a Telegram nudge: one message per user, per at-risk week, no repeats.

## Flow

1. **Cron** (Saturday 18:00, see [routes/console.php](routes/console.php#L72)) fires `StreakRemindCommand::handle()`.
2. The command queries all active Telegram connections with `notify_weekly_recap = true`, iterates each user, and applies four guards:
   - Skip if user is `is_demo` or null.
   - Skip if `WeeklySnapshot::consecutiveWeekStreak($userId)` returns `< 1` (no live streak).
   - Skip if the current week's `WeeklySnapshot` already has `runs > 0`.
   - Skip if `claim()` fails — `insertOrIgnore` on `streak_reminders` with a unique `(user_id, week_ending)` constraint, so repeated cron runs never double-send.
3. Dispatches `SendStreakReminderJob($userId, $streakWeeks)`.

The job re-checks all guards at runtime (user exists, Telegram connection active, `notify_weekly_recap` still true), then calls `TelegramClient::sendMessage` with:

> 🔥 Streak lari {n} minggu kamu belum ada progres minggu ini. Sempatkan lari sebelum minggu ini berakhir, biar streak-nya nggak putus.

The link points to the dashboard. See [[telegram-notifications]] for the broader Telegram integration.

## Idempotency

- `streak_reminders` table has a unique `(user_id, week_ending)` constraint — the same user in the same at-risk week can only receive one reminder, even if the command runs multiple times or the cron host restarts mid-iteration.
- The job re-checks connection status and opt-in at runtime, so a user who disconnects Telegram between dispatch and execution is never pestered.

## Schedule rationale

Saturday 18:00 gives the user a ~30-hour window (Saturday evening through Sunday midnight) to save their streak before the week closes. Earlier in the week would be premature (they might run Tuesday-Thursday); Sunday would be too late.

## Storage

| Table | Purpose |
|---|---|
| `streak_reminders` | Idempotency ledger: `(user_id, week_ending)` unique pair, no Eloquent model, accessed via `DB::table()` |

## Key dependencies

- `WeeklySnapshot::consecutiveWeekStreak()` — walks backward through contiguous running weeks; returns 0 if the most recent run is older than last Sunday.
- `TelegramConnection` — the opt-in connection model; `isRevoked()` checks for a null `revoked_at`.

## See also

[[telegram-notifications]] · [[telegram-account-linking]] · [[recaps]]
