---
title: Notification delivery
description: How a notification reaches the inbox, Telegram and web push — the router, the three channels, per-(analysis, channel) claims and their recovery, the quiet-hours hold, stale skips at delivery, the morning push slot, and the demo rule
tags: [architecture, notifications]
status: living
reviewed: 2026-10-09
code_refs:
  - app/Services/Notifications/ChannelRouter.php
  - app/Services/Notifications/NotificationDeliveryClaim.php
  - app/Services/Notifications/QuietHours.php
  - app/Services/Notifications/UsualRunTime.php
  - app/Services/Telegram/NotificationEligibility.php
  - app/Notifications/Channels/InAppChannel.php
  - app/Notifications/Channels/TelegramChannel.php
  - app/Notifications/Channels/IdempotentWebPushChannel.php
  - app/Notifications/Concerns/RechecksRouteAtDelivery.php
  - app/Notifications/AnalysisReadyNotification.php
  - app/Notifications/MorningBriefingNotification.php
  - app/Listeners/HoldNotificationsInQuietHours.php
  - app/Listeners/ReleaseTransientWebPush.php
  - app/Console/Commands/Notifications/ReleaseHeldNotificationsCommand.php
  - app/Console/Commands/Notifications/RecoverStaleNotificationDeliveriesCommand.php
  - app/Console/Commands/Notifications/MorningBriefingPushCommand.php
  - app/Console/Commands/Notifications/PrunePushSubscriptionsCommand.php
  - app/Jobs/Notifications/RetryStaleWebPushNotificationJob.php
  - app/Models/NotificationDelivery.php
  - app/Models/HeldNotification.php
  - app/Models/InboxNotification.php
  - routes/console.php
---

# Notification delivery

Every notification is a queued Laravel notification (`$tries = 3`, backoff 30 s then 120 s). Its `via()` decides *whether* it is sent and asks [ChannelRouter](app/Services/Notifications/ChannelRouter.php) *where*; Laravel then queues one send per channel. Each channel's send passes three gates before anything is written or delivered:

```
notify()  →  via(): whether (master switch, recency) + where (ChannelRouter)
          →  per channel, when the queued job runs:
               shouldSend()                      route recheck + stale skip
               NotificationSending listener      quiet-hours hold
               channel send()                    inbox write, or claim → provider → settle
```

The user-facing side (what each notification says, its push TTL, the inbox screen) lives in [[notification-inbox]] and [[telegram-notifications]]; this note is the path underneath them.

## Who sends what

The notification classes live in `app/Notifications/`. What differs between them is where they go, whether the master switch governs them, whether they carry a delivery key, and whether they expire:

| Notification | Triggered by | Channels | Master switch | Delivery key | Stale after |
|---|---|---|---|---|---|
| [AnalysisReadyNotification](app/Notifications/AnalysisReadyNotification.php) | `AnalysisService::markDone()` for a post-run, weekly or monthly recap | inbox + outbound | yes, plus the recency gate | the analysis id | — |
| [MorningBriefingNotification](app/Notifications/MorningBriefingNotification.php) | `briefing:morning-push` | outbound only | yes | the briefing row's id | its slot + 2 h, at most the day's end |
| [StreakReminderNotification](app/Notifications/StreakReminderNotification.php) | `streak:remind`, Saturday 18:00 | inbox + outbound | yes | — | end of the week |
| [RaceTomorrowNotification](app/Notifications/RaceTomorrowNotification.php) | `race:remind`, hourly 18:00–21:00 | inbox + outbound | yes | — | end of the day before the race |
| [RaceOutcomeNotification](app/Notifications/RaceOutcomeNotification.php) | `race:ask-outcome`, hourly 09:00–21:00 | inbox + outbound | yes | — | — |
| [TimeTrialNotification](app/Notifications/TimeTrialNotification.php) | `plan:settle-time-trials`, 09:05 | inbox + outbound | yes | — | — |
| [FitnessImprovedNotification](app/Notifications/FitnessImprovedNotification.php) | `fitness:notify-improvement`, 10:00 | inbox + outbound | yes | — | — |
| [StravaDisconnectedNotification](app/Notifications/StravaDisconnectedNotification.php) | [`StravaConnection::markRevoked()`](app/Models/StravaConnection.php), except on account deletion | inbox + outbound | no | — | — |
| [DayClampedNotification](app/Notifications/DayClampedNotification.php) | the readiness clamp recorder | inbox only | no | — | — |
| [TestNotification](app/Notifications/TestNotification.php) | the Settings page's test send | inbox + outbound | no | — | — |

The post-run and recap fan-out has extra guards on the narration side (no notify while dispatch is suppressed, for an early row awaiting its replay, or for a narrate-on-return fill); those live in [[ai-pipeline]].

## The router: where

[ChannelRouter](app/Services/Notifications/ChannelRouter.php) is the one answer to "where can this user be reached". [`channelsFor()`](app/Services/Notifications/ChannelRouter.php) always leads with the inbox, which has nothing to wire and nothing to mute, then adds each outbound channel that is both wired and enabled: Telegram when the bot token is configured, the connection is not revoked and `telegram_enabled` is on; web push when the user has a push subscription and `push_enabled` is on. A missing [NotificationPreference](app/Models/NotificationPreference.php) row means everything on. A mute leaves the channel wired, so un-muting needs no re-auth. [`inAppOnly()`](app/Services/Notifications/ChannelRouter.php) serves the record-not-interruption notifications and [`outboundOnly()`](app/Services/Notifications/ChannelRouter.php) the briefing, which is an interruption with nothing new to record.

[`canReach()`](app/Services/Notifications/ChannelRouter.php) and the query-level [`scopeReachable()`](app/Services/Notifications/ChannelRouter.php) answer the outbound question only, so the always-on inbox cannot make them trivially true; `streak:remind` and `briefing:morning-push` select users with the scope. The router answers *where*, never *whether*: the master switch and the recency gate stay in each notification's `via()` and in [NotificationEligibility](app/Services/Telegram/NotificationEligibility.php). Decisions: [[inbox-is-an-always-on-channel]], [[the-briefing-also-goes-to-telegram]]; the two preference axes are walked through in [[telegram-notifications]].

Maintainer alerts and the Telegram bot's replies to `/start` and `/stop` are not notifications and bypass the router entirely; see [[telegram-notifications]].

## Rechecks at delivery

Laravel evaluates `via()` once, at enqueue. Notifications using [RechecksRouteAtDelivery](app/Notifications/Concerns/RechecksRouteAtDelivery.php) re-run it against a freshly loaded user in `shouldSend()` before each outbound send, and both outbound channels also call [`ChannelRouter::eligibleUserFor()`](app/Services/Notifications/ChannelRouter.php) before taking a claim. A mute, a disconnect or a switched-off master switch set after enqueue therefore suppresses that channel without creating or spending a claim. The inbox send is never rechecked: once chosen, the record is kept.

## Stale skips at delivery

The three time-boxed notifications set a `$staleAfter` instant in their constructor (see the table). Past it, `shouldSend()` skips every outbound channel, logs `notifications.stale_skipped`, and for a keyed notification writes a `Failed` delivery row with the reason through [`NotificationDeliveryClaim::recordUnclaimedFailed()`](app/Services/Notifications/NotificationDeliveryClaim.php). The inbox row is still written. A notification released from quiet hours is judged as of its `heldAt`, so time spent held never makes it stale. This is how a queue outage or a maintenance window avoids delivering a reminder after its useful window, the rule [[scheduler-maintenance-recovery]] sets for missed reminders, races and morning pushes; the held-time rule is [[quiet-hours-hold-every-notification]]'s.

## The channels

- **Inbox** — [InAppChannel](app/Notifications/Channels/InAppChannel.php) writes through [`InboxNotification::record()`](app/Models/InboxNotification.php), idempotent on the unique `(user_id, dedupe_key)` pair. A message without its own key falls back to the notification's id, which Laravel assigns before queuing, so a queue retry or a quiet-hours replay writes one row. The post-run and recap rows key on the analysis, so a re-narration does not stack a second row. Decision: [[inbox-is-an-always-on-channel]].
- **Telegram** — [TelegramChannel](app/Notifications/Channels/TelegramChannel.php) claims before sending when the message carries a delivery key. A chat-specific failure (bot blocked, chat gone) revokes the connection; a 401 or 404 keeps the link, alerts the maintainer and rethrows; any other 4xx is logged and not retried; a keyed send that lost its connection to the Bot API is settled `Abandoned`, since Telegram may already have accepted it; everything else is settled `Failed` and rethrown so the queue retry can re-claim it. The status-by-status rules are in [[telegram-notifications]].
- **Web push** — [IdempotentWebPushChannel](app/Notifications/Channels/IdempotentWebPushChannel.php) wraps the package channel with the same claim and reads its per-subscription reports. One accepting subscription settles `sent`. When none accepted and any failure was a 429, 5xx or network error, it settles `Failed` and throws `TransientWebPushException`; [ReleaseTransientWebPush](app/Listeners/ReleaseTransientWebPush.php) then re-queues the job after the push service's `Retry-After`, capped at ten minutes, instead of the fixed backoff. Any other rejection settles `Failed` and is not retried; the package itself deletes a subscription a push service reports expired (404/410). Every push carries the inbox unread count in `data.unread` for the app-icon badge ([[the-briefing-also-goes-to-telegram]]), and sets its own TTL at send time ([[notification-inbox]]).

Push subscriptions are otherwise pruned only on exact evidence: a resubscribe replaces only the endpoint its own install names ([[a-resubscribe-replaces-only-its-own-push-subscription]]), and `notifications:prune-push-subscriptions` deletes, daily at 02:35, a subscription no installed app has reported for 60 days ([PrunePushSubscriptionsCommand](app/Console/Commands/Notifications/PrunePushSubscriptionsCommand.php), [[unseen-push-subscriptions-are-pruned-after-60-days]]).

## Delivery claims

A [NotificationDelivery](app/Models/NotificationDelivery.php) row is both the idempotency claim taken before an outbound send and the outcome recorded after it, one per `(analysis_id, channel)` with the channel as `telegram` or `webpush`. Only the analysis-backed notifications (the post-run and recap narrations and the morning briefing) carry a delivery key; every other outbound send goes unclaimed, so a queue retry of it can repeat.

[`NotificationDeliveryClaim::claim()`](app/Services/Notifications/NotificationDeliveryClaim.php) inserts a pending row at version 1, or takes over an existing row with a conditional version bump: a `Failed` row on either channel, or a web-push row still pending 15 minutes after its claim. A worker settles only the pending row carrying the version it received ([`markSent()`](app/Services/Notifications/NotificationDeliveryClaim.php), [`markFailed()`](app/Services/Notifications/NotificationDeliveryClaim.php), [`markAbandoned()`](app/Services/Notifications/NotificationDeliveryClaim.php)), so a late worker's result is a logged no-op rather than an overwrite. The statuses are [NotificationDeliveryStatus](app/Enums/NotificationDeliveryStatus.php); the Pulse [NotificationDeliveryHealth](app/Livewire/Pulse/NotificationDeliveryHealth.php) card shows the per-channel counts and the latest failed and abandoned rows. Decision: [[fenced-notification-delivery-recovery]].

## Recovery

`notifications:recover-deliveries` runs every five minutes ([RecoverStaleNotificationDeliveriesCommand](app/Console/Commands/Notifications/RecoverStaleNotificationDeliveriesCommand.php)) and scans claims still pending 15 minutes after they were taken, through [`recoverStale()`](app/Services/Notifications/NotificationDeliveryClaim.php):

- **Telegram** becomes terminal `Abandoned` with its version bumped. Nothing resends it automatically or manually; the inbox still holds the narration.
- **Web push** is left unchanged and gets a queued [RetryStaleWebPushNotificationJob](app/Jobs/Notifications/RetryStaleWebPushNotificationJob.php). The job rebuilds the notification (the briefing or the analysis-ready one) and sends it on web push alone; the channel takes the stale claim over with a new version. If the analysis or its user is gone, or current routing no longer allows web push, the job settles the old version `Failed` with that reason. Because the row stays discoverable until a worker claims it, a lost dispatch is found again by the next sweep. A retry that lands in quiet hours returns without touching the row, and the first sweep after 04:00 retries it. The rebuilt analysis-ready notification measures the recency gate from the delivery row's claim time rather than from the retry; a reclaim resets that time, so a retry of a retry measures from the last reclaim.

Decisions: [[queue-resilient-stale-web-push-retries]], which supersedes the web-push handoff of [[fenced-notification-delivery-recovery]].

## Quiet hours: hold and release

[QuietHours](app/Services/Notifications/QuietHours.php) is a fixed 22:00–04:00 window on the app clock, switched by `notifications.hold_during_quiet_hours` (on in config, off in the base test case). Inside it, [HoldNotificationsInQuietHours](app/Listeners/HoldNotificationsInQuietHours.php) listens for `NotificationSending`, which Laravel fires after `shouldSend()` and before the channel's `send()`. It stores the serialised notification as one [HeldNotification](app/Models/HeldNotification.php) row per channel and cancels the send, before any claim or inbox write. It holds every channel, the inbox included, for every account including the demo; only `TestNotification` passes. A held briefing carries a unique `dedupe_key` of its briefing id and channel, so the push sweep's repeated ticks inside its catch-up window hold one copy.

`notifications:release-held` runs every five minutes and does nothing while the window is open ([ReleaseHeldNotificationsCommand](app/Console/Commands/Notifications/ReleaseHeldNotificationsCommand.php)). Outside it, it walks the held rows oldest first and, one transaction per row, deletes the row, stamps the notification's `heldAt`, and dispatches the same one-channel `SendQueuedNotifications` job Laravel queued at trigger time. The replay goes through `shouldSend()`, routing, the claim and the inbox dedupe like any send, so a mute set overnight is respected. A payload that cannot be restored is dropped and fails the run; a failed dispatch leaves the row for the next tick; a deleted subject drops the row with a warning.

Held time is never late: push TTLs are computed at the actual send, the post-run and recap recency gate is measured as of the notification's `triggeredAt` ([`NotificationEligibility::isRecentEnoughToAutoNotify()`](app/Services/Telegram/NotificationEligibility.php)), and staleness as of `heldAt`. Decision: [[quiet-hours-hold-every-notification]].

## Morning push timing

`ai:daily-briefing` narrates each active athlete's briefing at 00:01; `briefing:morning-push` only delivers it, every fifteen minutes ([MorningBriefingPushCommand](app/Console/Commands/Notifications/MorningBriefingPushCommand.php)). The athlete's slot is [UsualRunTime](app/Services/Notifications/UsualRunTime.php): the median local start time of their last 20 runs, or 06:00 with fewer than five, floored to the quarter hour. It is memoised for the day and cleared when [WeeklyAggregator](app/Services/Run/Metrics/WeeklyAggregator.php) rebuilds the athlete's history.

Each tick selects outbound-reachable athletes whose master switch is on, and sends to those whose slot is at or before now and no more than 60 minutes ago, whose session today is not skipped, whose briefing for today is `done` with content, and who do not yet have a delivery row on every outbound channel. A pending or failed briefing is skipped, never generated, so the push adds no LLM call. The claim on the briefing id makes it one send per channel per day, and the stale cutoff (slot + 2 h) keeps a delayed job from arriving hours late. A slot inside quiet hours is held and goes out at 04:00. Decisions: [[the-briefing-arrives-when-you-run]] for the slot and the sweep, [[the-briefing-also-goes-to-telegram]] for the channels.

## The demo rule

The shared demo identity has no outbound channel: `ChannelRouter` returns none for `is_demo`, below the wiring check, so it holds even if a Telegram link or push subscription were ever attached, and `scopeReachable()` excludes it at the query level. It still gets the inbox, so the public demo shows a populated notification centre, and its inbox rows are held in quiet hours like anyone's. Decision: [[demo-notifications-are-inbox-only]], which revises one consequence of [[inbox-is-an-always-on-channel]].

## See also

[[notification-inbox]] · [[telegram-notifications]] · [[streak-reminders]] · [[scheduler]] · [[ai-pipeline]]
