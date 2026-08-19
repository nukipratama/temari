---
title: The inbox is an always-on notification channel
description: The notification centre is wired into ChannelRouter as a third channel that is never unwired and never muted, so every notification Temari sends leaves a durable record even when no outbound channel can deliver it.
tags: [decision, notifications]
status: accepted
reviewed: 2026-08-13
code_refs:
  - app/Services/Notifications/ChannelRouter.php
  - app/Notifications/Channels/InAppChannel.php
  - app/Models/InboxNotification.php
  - app/Notifications/Messages/InboxMessage.php
  - app/Notifications/UnlockGrantedNotification.php
  - app/Actions/Gamification/GrantEligibleUnlocksAction.php
  - app/Services/Inertia/NotificationProps.php
  - database/migrations/2026_08_13_141500_create_notifications_table.php
---

# The inbox is an always-on notification channel

**Status:** Accepted (documented 2026-08-13)

> **One consequence below is superseded (noted 2026-08-14) by [[demo-notifications-are-inbox-only]].** The note says the demo account still receives nothing and its inbox stays empty. [ChannelRouter::channelsFor](app/Services/Notifications/ChannelRouter.php#L50) now always leads with `InAppChannel`, and only `outboundChannelsFor()` excludes the demo — so the demo inbox does fill, it just never sends outbound. The always-on-channel decision this note records is unchanged.

## Context

Everything Temari said was write-once and read-never. A post-run story reached
Telegram or a lock screen and then existed only as an `ai_analyses` row nobody
surfaced; a streak nudge was gone the moment it was dismissed; an unlock was a
session flash ([GrantEligibleUnlocksAction](../../app/Actions/Gamification/GrantEligibleUnlocksAction.php)),
which meant an unlock earned during a background ingest, with no session to flash
into, was celebrated to nobody at all.

The obvious shape for a notification centre is a second write next to each
existing send. That is exactly how "where can this user be reached" drifted out
of sync across six call sites before [ChannelRouter](../../app/Services/Notifications/ChannelRouter.php)
existed, and a per-call-site inbox write would reintroduce the same failure in a
worse place: the missing row would be the *record*, discovered weeks later.

## Decision

The inbox is a Laravel notification channel
([InAppChannel](../../app/Notifications/Channels/InAppChannel.php)) returned by
`ChannelRouter::channelsFor()` like any other, so a notification lands in the
inbox for the same reason it lands on Telegram: it asked the router where to go.
No call site writes an inbox row itself.

Three consequences follow, each deliberate:

- **It has no mute.** Telegram and web push carry a wired condition and a mute
  ([NotificationPreference](../../app/Models/NotificationPreference.php)); the
  inbox carries neither. Muting an interruption spares the user something.
  Muting the record just loses their history, silently and permanently.
- **`canReach()` stopped meaning "any channel".** It is now the *outbound*
  question, because both callers ("does the test send prove anything",
  "is this user worth a Saturday streak nudge") are asking about interruption,
  not about record-keeping. An always-on inbox would have made both trivially
  true. `scopeReachable()` matches it, so the streak nudge still only goes to
  users an outbound channel can reach in time for it to be true.
- **The *whether* gates are untouched.** A notification suppressed by the demo
  flag, the master switch or the recency window is not sent, so no row is
  written. Only the *where* gates (unwired, revoked, muted) now leave a record
  behind rather than nothing.

Idempotency is a unique `(user_id, dedupe_key)` pair rather than the
per-`(analysis, channel)` claim the outbound channels share
([NotificationDeliveryClaim](../../app/Services/Notifications/NotificationDeliveryClaim.php)),
because streak and unlock notifications have no analysis to key on. A message
that names no key falls back to the notification's own id, which Laravel assigns
before queuing and which therefore survives a retry unchanged.

Rows store a **replay payload**, not a rendering. A post-run row carries the
`run_card_id`, which the existing `api.cards.replay` endpoint re-arms into the
real full-screen reveal; an unlock row carries the celebration payload verbatim,
so the same takeover component replays weeks later. Storing a summary line would
have made the inbox a log; storing ids makes it a second door into the app.

Unlocks are the one notification routed **inbox-only** (`inAppOnly()`). They
arrive in batches and are earned rather than time-sensitive, so fanning them out
to Telegram and the lock screen would be new noise nobody asked for. Routing
them through the router anyway keeps channel class-strings in one place.

## Consequences

- Users with no Telegram link and no push subscription now receive everything,
  in the app. That was previously a silent no-op path.
- The demo account still receives nothing: its `via()` guards return no channels
  at all, so a public demo inbox is empty until that is deliberately changed.
  An inbox write costs nothing, so this is a product call, not a billing one
  (see [[demo-user-billing-exclusion]]).
- Retention is **undecided**. Nothing prunes the table; no row is ever deleted
  except by the user cascade. A retention policy needs its own decision.
- The `notifications` table shadows the name Laravel's own
  `DatabaseNotification` would claim. The model is called `InboxNotification`
  and the relation `User::inboxNotifications()`; the `Notifiable` trait's
  `notifications()` is unused and would not match this schema.

## See also

[[telegram-notifications]] · [[streak-reminders]] · [[demo-user-billing-exclusion]]
