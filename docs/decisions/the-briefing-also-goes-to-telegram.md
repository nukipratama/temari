---
title: The briefing also goes to Telegram, and the app-icon badge counts inbox rows
description: The morning briefing routes to every outbound channel rather than web push alone, and the PWA app-icon badge is driven by the in-app inbox's unread count instead of the service worker's own notification tray.
tags: [decision, notifications]
status: accepted
reviewed: 2026-09-16
code_refs:
  - app/Notifications/MorningBriefingNotification.php
  - app/Services/Notifications/ChannelRouter.php
  - app/Console/Commands/Notifications/MorningBriefingPushCommand.php
  - resources/js/lib/appBadge.ts
  - public/sw.js
---

# The briefing also goes to Telegram, and the app-icon badge counts inbox rows

**Status:** Accepted (decided 2026-09-16)

Amends the delivery half of [[the-briefing-arrives-when-you-run]]; that note's timing decision
(the median-start bucket, and that the sweep generates nothing) stands unchanged.

## Context

Two surfaces were each right about their own mechanism and wrong about their source of truth.

**The briefing was web push alone.** `ChannelRouter::pushOnly()` existed for exactly one caller, and
an athlete who had connected Telegram but never granted a push permission — the normal state on
desktop, and on iOS until the app is installed to the home screen — got nothing at all. The
argument for push-only was never "not Telegram", it was "not the inbox": the briefing is already on
the dashboard, so an inbox *record* of it would be a record of nothing new. Telegram is the same
kind of thing a lock screen is — an interruption timed to a moment — so the distinction the routing
drew was between record and interruption, not between the two outbound channels.

**The app-icon badge counted the wrong things.** `syncAppBadgeOnVisible()` read
`registration.getNotifications()`, the browser's own push tray. The bell inside the app counts
`InboxNotification` rows. Those two numbers agree only by accident: a push the OS coalesced, a
notification swiped on another device, or an inbox row written with no push subscription wired all
make them diverge, and the icon is the number the athlete sees without opening anything.

## Decision

**The briefing routes to every outbound channel.** `ChannelRouter::pushOnly()` becomes
`outboundOnly()`, which is the existing `outboundChannelsFor()` — Telegram when wired and un-muted,
web push when subscribed, the inbox never. The demo identity still gets nothing, decided in that
one place as before. `MorningBriefingPushCommand` selects with the shared `scopeReachable()` rather
than a push-only scope of its own, so the every-quarter-hour sweep queues for a Telegram-only
athlete instead of skipping them; `scopePushReachable()` had no other caller and is gone.

Idempotency needed nothing new: the shared delivery claim is keyed on
(analysis, **channel**), so the briefing row's id claims once per channel and a queued retry still
cannot double-send either one.

**The badge is the inbox's unread count.** `unreadNotifications` is already a shared Inertia prop
(the bell's own source), so `syncAppBadge()` is called with it on every visit — including the
`router.reload({ only: ['unreadNotifications'] })` the inbox fires after marking rows read, which
is what clears the badge the moment the inbox is read. Each web push additionally carries that
count in `data.unread`, so the service worker can keep the badge honest while the app is closed;
the tray count survives only as the fallback before a worker has seen its first push.

## Consequences

- An athlete with Telegram and no push subscription now receives the briefing. This is a new
  message to an existing audience, not a new audience: the master switch and the per-channel mute
  both already govern it, and Settings grows no row.
- The briefing is the one notification that reaches an outbound channel without leaving an inbox
  row, so the badge it triggers is the count of *other* unread rows. That is correct rather than
  surprising: there is nothing in the inbox to read.
- The badge can now be wrong in one direction the tray never was — it trails until the next visit
  or push, because the inbox count only changes server-side. Dismissing a push no longer decrements
  it, which is the point: dismissing a notification is not reading the inbox.
- `getNotifications()` is no longer read for counting, so the iOS `notificationclose` gap
  ([[installed-app-shell]]) stops mattering for the badge.

## See also

[[the-briefing-arrives-when-you-run]] — the timing decision this amends
[[inbox-is-an-always-on-channel]] · [[demo-notifications-are-inbox-only]] · [[notification-inbox]]
