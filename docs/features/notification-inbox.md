---
title: Notification inbox
description: The /inbox notification centre — a paginated record of everything Temari sent, with unlock and post-run rows that re-run the original celebration rather than summarising it.
tags: [feature, notifications]
status: living
reviewed: 2026-08-30
code_refs:
  - app/Http/Controllers/InboxController.php
  - app/Models/InboxNotification.php
  - app/Http/Controllers/Api/NotificationReadController.php
  - app/Services/Inertia/NotificationProps.php
  - resources/js/pages/Inbox.tsx
  - resources/js/components/inbox/InboxRow.tsx
  - resources/js/components/inbox/inboxBuckets.ts
  - resources/js/components/NotificationBell.tsx
  - resources/js/components/celebrations/AccessoryUnlockModal.tsx
---

# Notification inbox

`/inbox` is the read side of the always-on in-app channel ([[inbox-is-an-always-on-channel]]).
Every row is something Temari already sent; nothing is written here, and nothing is deleted here.

## The prop shape

There is no listing API. The page is a normal Inertia page
([InboxController](../../app/Http/Controllers/InboxController.php#L27)) because the inbox is a
destination with its own URL, not a widget that polls: a push tap has to land on it cold, and
`unreadNotifications` already rides on every page as a shared prop
([NotificationProps](../../app/Services/Inertia/NotificationProps.php#L37)).

Rows arrive **flattened**, not as the stored `payload` blob. The controller lifts out the deep
link and the replay handles ([InboxController](../../app/Http/Controllers/InboxController.php#L50))
so the page never reads untyped JSON, and the shape is a declared TypeScript interface
(`InboxItem` in [types/inertia.ts](../../resources/js/types/inertia.ts)) rather than
`Record<string, unknown>`. `created_at` ships as `toIso8601String()` — a true instant with its
`+07:00` offset, so the frontend reads it with `formatRelativeId`
([pace.ts](../../resources/js/lib/pace.ts#L104)) and never with the naive wall-clock parser the
Strava dates need.

The list is paginated at 20 ([InboxController](../../app/Http/Controllers/InboxController.php#L25)).
Retention is undecided and nothing prunes the table, so pagination is what keeps an old account's
inbox usable without deciding how long a record lives.

## Replay, not a summary

Two row kinds carry enough to re-run the celebration they are a record of:

- **Post-run** rows carry `run_card_id`. The button POSTs to `api.cards.replay`
  ([CardReplayController](../../app/Http/Controllers/Api/CardReplayController.php#L18)), which
  re-arms `pending_reveal_card_id`, then reloads the `pendingReveal` shared prop so the real
  full-screen `CardReveal` mounted in [AppShell](../../resources/js/layouts/AppShell.tsx#L93)
  plays again. Same endpoint and same sequence the run detail page uses.
- **Unlock** rows carry the celebration verbatim, so the page hands it straight back to
  [AccessoryUnlockModal](../../resources/js/components/celebrations/AccessoryUnlockModal.tsx) — the
  same takeover that played at grant time. The modal no longer gates itself on `is_major`: the
  caller owns that call, so AppShell still only takes over for a major grant while a replay the
  user explicitly asked for always gets the full thing.

Everything else (recaps, the streak nudge) is a deep link into the page the notification was
about, which is the same URL its web push already carried.

## Grouped sections and the time toggle

Rows render grouped into **Today / This Week / Earlier**
([inboxBuckets.ts](../../resources/js/components/inbox/inboxBuckets.ts)), a pure client-side
grouping over whatever page of rows is already loaded, no backend shape change. The "this week"
boundary is Monday-start, matching the backend's own week convention (`startOfWeek(Carbon::MONDAY)`,
e.g. [Periodizer](../../app/Services/Run/Plan/Periodizer.php#L56)) rather than a locale default.
`created_at` is a true instant, so bucketing reads it with plain `Date` parsing and deliberately does
not reuse `pace.ts`'s `mondayOf`, which is built for Strava's naive `start_date_local` values.

Tapping a row's timestamp toggles it between relative (`formatRelativeId`) and absolute
(`formatAbsoluteId`) display, per row, client-only state.

Pagination stays the existing Newer/Older page nav rather than adopting a cumulative "load more":
the deep-link resolver ([InboxController](../../app/Http/Controllers/InboxController.php#L92)) jumps
straight to the page a given row sits on, which assumes a single page is rendered at a time. A
cumulative loader would need every page up to that one merged client-side first, a materially
different pagination shape than what's wired today.

## Read state

Reading is per-row and idempotent: opening a deep link or replaying a celebration POSTs to
[NotificationReadController](../../app/Http/Controllers/Api/NotificationReadController.php#L19),
which is scoped through the user's own relation. The page marks the row read optimistically and
reloads only `unreadNotifications`, which is what the bell in
[TopNav](../../resources/js/components/TopNav.tsx) / [MobileTopBar](../../resources/js/components/MobileTopBar.tsx)
renders. There is no "mark all read": the unread count is a count of things not looked at, and a
button that lies about that is worse than a count that stays high.

`/inbox?item={id}` is the per-row deep link. The controller resolves which page that row sits on
([InboxController](../../app/Http/Controllers/InboxController.php#L92)) so the target is on screen
even when it has been paged past, and arriving on a row counts as reading it.

## Empty inbox

An empty inbox is a normal state, not a failure: a new account has nothing yet, and a backfill can
take a while to produce the first notifiable analysis. The empty state says the inbox fills itself
and asks the user for nothing.

The public demo is **not** one of those cases. The demo identity is routed to the inbox and to no
outbound channel ([[demo-notifications-are-inbox-only]]), so its inbox carries the unlocks the seed
grants. Post-run and recap rows are still absent there, because the seed's no-LLM wrapper suppresses
the notification fan-out along with the job dispatch.

## See also

[[inbox-is-an-always-on-channel]] · [[telegram-notifications]] · [[streak-reminders]] · [[gamification]]
