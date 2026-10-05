---
title: Quiet hours hold every notification until 04:00
description: Between 22:00 and 04:00 WIB every notification except the manual test is held per channel in the database and re-queued, oldest first, by a five-minute release that waits for the window to end.
tags: [decision, notifications]
status: accepted
reviewed: 2026-10-05
code_refs:
  - app/Services/Notifications/QuietHours.php
  - app/Listeners/HoldNotificationsInQuietHours.php
  - app/Models/HeldNotification.php
  - database/migrations/2026_10_05_000100_create_held_notifications_table.php
  - app/Console/Commands/Notifications/ReleaseHeldNotificationsCommand.php
  - app/Jobs/Notifications/RetryStaleWebPushNotificationJob.php
  - app/Notifications/AnalysisReadyNotification.php
  - app/Services/Telegram/NotificationEligibility.php
  - config/notifications.php
  - routes/console.php
---

# Quiet hours hold every notification until 04:00

**Status:** Accepted (decided by the owner 2026-10-05, issue #1768; built in #1769)

## Context

The weekly recap pushes from Monday 00:16 onwards, and post-run and recap narrations finish at any
hour through `ai:self-heal`, `ai:catch-up` and the overnight Strava drains. Nothing checked the
hour, so 16% of outbound messages in a month landed between 22:00 and 06:00. Every athlete is in
WIB, so one fixed window on the app clock covers everyone.

## Decision

From 22:00 up to 04:00 on the app clock ([QuietHours](../../app/Services/Notifications/QuietHours.php#L24)),
every notification is held on every channel, the inbox included, for every account including the
demo. Only `TestNotification` bypasses it. The Telegram bot's replies and maintainer alerts are not
notifications and never reach the hold.

**Where it holds.** A `NotificationSending` listener
([HoldNotificationsInQuietHours](../../app/Listeners/HoldNotificationsInQuietHours.php#L20)) runs
after a notification's own `shouldSend()` and before its channel's `send()`, so before any delivery
claim and before the inbox write. It stores one `held_notifications` row per channel (athlete,
channel, the serialised notification with its id, `held_at`) and returns `false`, which cancels that
channel's send. The queued job that carried it completes normally.

**How it releases.** `notifications:release-held` runs every five minutes
([routes/console.php](../../routes/console.php#L171)) and does nothing while the window is open.
Outside it, it walks every held row in id order and, in one transaction per row, deletes the row and
re-queues it as the same one-channel `SendQueuedNotifications` job Laravel queued at trigger time
([ReleaseHeldNotificationsCommand](../../app/Console/Commands/Notifications/ReleaseHeldNotificationsCommand.php#L53)).
The replay goes through the normal path: `shouldSend()`, routing, the delivery claim, the inbox
dedupe key, `$tries`, backoff, `Retry-After` and `failed_jobs` all apply as they would have at
trigger time. A mute the athlete set overnight is respected; that is routing, not the hold dropping
anything. The released count goes to the log (`notifications.held.released`).

**Exactly once.**

| Situation | Result |
|---|---|
| Release runs twice | The second run finds no rows. |
| Two releases overlap | `withoutOverlapping`; and only the transaction whose delete removed the row queues it. |
| 04:00 tick missed (deploy, outage) | The next five-minute run outside the window releases everything. |
| Crash after queueing, before commit | The row survives and is queued again. The inbox write dedupes on the kept notification id or its dedupe key, and a keyed Telegram or web push dedupes on its delivery claim. An unkeyed Telegram or web push (streak, race, Strava) can repeat, as a queue retry of the same job already could. |
| Recovery runs during the window | Held work has no `notification_deliveries` row, so `notifications:recover-deliveries` cannot retry or abandon it. A stale web-push retry that lands in the window returns without settling the claim ([RetryStaleWebPushNotificationJob](../../app/Jobs/Notifications/RetryStaleWebPushNotificationJob.php#L36)); the first recovery run after 04:00 retries it. |
| A held notification's subject is deleted | Its row is dropped with a `notifications.held.subject_missing` warning, as the queued job would have failed. A deleted athlete cascades their rows away. |

**Held time is never late.** Push TTLs are computed in `toWebPush()` at the actual send, so a
released push gets its full TTL; a deadline already past still clamps to one second. The post-run
and recap recency gate is measured as of the trigger
([AnalysisReadyNotification](../../app/Notifications/AnalysisReadyNotification.php#L77),
[NotificationEligibility](../../app/Services/Telegram/NotificationEligibility.php#L50)), so a
narration that was fresh at 23:00 still pushes at 04:00.

**Tests.** `config/notifications.php` keeps the hold on; the base `TestCase` switches it off so the
suite does not depend on the hour it runs at, and the quiet-hours tests switch it back on.

## Rejected alternative

Delaying each queued notification with `withDelay()` until 04:00 adds no table, but every delayed
job then shares one release score, so the Redis delayed set does not keep oldest-first order. Held
work would live only in Redis, and a job queued at 21:59 that runs at 22:01 would not be held.

## Consequences

- **Enables:** no push, Telegram message or inbox row between 22:00 and 04:00, with the inbox and
  the lock screen staying in step.
- **Costs:** a held notification is serialised into the database for up to six hours, and a briefing
  slot inside the window arrives at 04:00 with whatever TTL is left of its day.
- **Privacy:** the held row carries what the queued job already carried (model ids and the
  notification's own fields), and is deleted on release or with the athlete.

## See also

- [[notification-inbox]] · [[telegram-notifications]] · [[scheduler]]
- [[fenced-notification-delivery-recovery]] · [[queue-resilient-stale-web-push-retries]]
- [[the-briefing-arrives-when-you-run]]
