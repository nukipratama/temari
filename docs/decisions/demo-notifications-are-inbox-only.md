---
title: The demo identity is routed inbox-only, not silenced
description: Demo notifications are routed to the in-app inbox and to no outbound channel, so the public demo shows a populated notification centre while never spending the shared identity's Telegram thread or lock screen.
tags: [decision, notifications]
status: accepted
reviewed: 2026-08-31
code_refs:
  - app/Services/Notifications/ChannelRouter.php
  - app/Notifications/AnalysisReadyNotification.php
  - app/Notifications/UnlockGrantedNotification.php
  - app/Notifications/StreakReminderNotification.php
  - app/Notifications/TestNotification.php
  - app/Http/Controllers/InboxController.php
---

# The demo identity is routed inbox-only, not silenced

**Status:** Accepted (documented 2026-08-13)

## Context

[[inbox-is-an-always-on-channel]] shipped the inbox as a router channel, and
recorded as a consequence that the demo account would still receive nothing:
each notification's `via()` opened with `if ($notifiable->is_demo) return []`,
which suppressed the record along with the interruption.

Building the notification centre on top of that made the cost visible. `/inbox`
would render blank for every visitor to the public demo, which is the one
audience the page exists to sell to.

## Decision

The demo identity has **no outbound channel**, decided once in
[ChannelRouter](../../app/Services/Notifications/ChannelRouter.php) rather than
re-derived in four `via()` methods. `channelsFor()` therefore returns the inbox
alone for it, `canReach()` is false, and `scopeReachable()` excludes it at the
query level so the two never disagree.

The demo exclusion ADRs it might look like this contradicts are about spend:
[[demo-user-billing-exclusion]] and [[demo-triggers-served-rule-based]] keep the
shared account off Strava reads and LLM calls. An inbox row is a local insert.
It costs neither, so this is a product call rather than a billing one.

Two lines hold:

- **Nothing leaves the app on the shared identity.** Telegram and web push stay
  off for `is_demo`, now more firmly than before: the guard sits below the
  wiring check, so it holds even if a connection or a push subscription is ever
  attached to the demo user. A test pins `canReach()` false and the fan-out
  writing a row while sending nothing.
- **The *whether* gates are untouched.** `StreakRemindCommand` still selects
  `is_demo = false` itself, and the demo's exclusion from every auto-billing
  scheduler is unchanged. This decision moves only *where* an already-decided
  notification goes.

## Consequences

- The public demo's inbox is populated by the unlocks granted during
  `demo:seed`, each replaying its real celebration takeover.
- This revises one consequence of [[inbox-is-an-always-on-channel]] ("the demo
  account still receives nothing"). The rest of that decision stands unchanged:
  the inbox is still a router channel, still unmuteable, and `canReach()` still
  means outbound.

**2026-08-31 correction (`F7`):** the first bullet above never actually held —
`UnlockGrantedNotification implements ShouldQueue`, and nothing in `demo:seed`
ever runs a queue worker, so the notification sat in the `jobs` table and
`InAppChannel::send()` never ran; the demo inbox was silently empty (0 rows,
not 21) until `F7` fixed the seed path itself (`DemoRunSeeder::withSyncQueue()`
forces the queue connection to `sync` around the unlock-granting call so it
executes inline). Separately, the second bullet's "has not been made" is no
longer true either: `F7` added `DemoRunSeeder::seedNarrationInboxEntries()`,
which builds a post-run and a weekly-recap inbox row directly from an
already-`Done` Analysis row's `AnalysisReadyNotification::toInbox()` output and
persists it via `InboxNotification::record()` — deliberately bypassing
`AnalysisService::withoutDispatching()`'s notification suppression rather than
lifting it (which would also need to reason about job dispatch, cooldowns and
real-Azure-eligibility checks this seed path must never trigger). Both fixes
are seed-path changes only; `ChannelRouter`, `via()`, and the decision recorded
above are unchanged. See `plan/slices/08-F7-demo-data-and-fixtures.md`.

## See also

[[inbox-is-an-always-on-channel]] · [[notification-inbox]] · [[demo-user-billing-exclusion]] · [[demo-triggers-served-rule-based]]
