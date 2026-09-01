---
title: Notification inbox
description: The /inbox notification centre — a growing window over everything Temari sent, each row a deep link back into the page it was about.
tags: [feature, notifications]
status: living
reviewed: 2026-09-01
code_refs:
  - app/Http/Controllers/InboxController.php
  - app/Models/InboxNotification.php
  - app/Http/Controllers/Api/NotificationReadController.php
  - app/Services/Inertia/NotificationProps.php
  - resources/js/pages/Inbox.tsx
  - resources/js/components/inbox/InboxRow.tsx
  - resources/js/components/inbox/inboxBuckets.ts
  - resources/js/components/NotificationBell.tsx
---

# Notification inbox

`/inbox` is the read side of the always-on in-app channel ([[inbox-is-an-always-on-channel]]).
Every row is something Temari already sent; nothing is written here, and nothing is deleted here.

## The prop shape

There is no listing API. The page is a normal Inertia page
([InboxController](../../app/Http/Controllers/InboxController.php#L36)) because the inbox is a
destination with its own URL, not a widget that polls: a push tap has to land on it cold, and
`unreadNotifications` already rides on every page as a shared prop
([NotificationProps](../../app/Services/Inertia/NotificationProps.php#L37)).

Rows arrive **flattened**, not as the stored `payload` blob. The controller lifts out the deep
link and the replay handles ([InboxController](../../app/Http/Controllers/InboxController.php#L70))
so the page never reads untyped JSON, and the shape is a declared TypeScript interface
(`InboxItem` in [types/inertia.ts](../../resources/js/types/inertia.ts)) rather than
`Record<string, unknown>`. `created_at` ships as `toIso8601String()` — a true instant with its
`+07:00` offset, so the frontend reads it with `formatRelativeId`
([pace.ts](../../resources/js/lib/pace.ts#L104)) and never with the naive wall-clock parser the
Strava dates need.

The list is a **growing window**, not a pager. The first request ships 20 rows
([InboxController](../../app/Http/Controllers/InboxController.php#L32)); each "load older" press
asks for `?shown=` twenty more and the server also says whether anything sits behind what it sent,
so the button hides itself at the end. The requested size is snapped up to the page step and capped
([InboxController](../../app/Http/Controllers/InboxController.php#L130)), so a hand-typed `?shown=`
cannot ask for an unbounded scan. Retention is undecided and nothing prunes the table, so the window
is what keeps an old account's inbox usable without deciding how long a record lives.

## Deep links, not replays

`PP3` cut both celebration replays: the card-reveal modal and its `api.cards.*` endpoints, and the
accessory-unlock takeover. Rows are now a record and a deep link, nothing more. Every row's only
action is the "Open" link into the page the notification was about, which is the same URL its web
push already carried.

An **unlock** row's rarity badge is resolved read-side from the unlock catalog by `unlock_key`
([InboxController](../../app/Http/Controllers/InboxController.php#L150)) rather than read out of the
stored payload, which never carried one — so rows recorded before the badge existed are rated too,
and a key outside the catalog (the per-season `season.{id}.*` namespace) simply stays unrated and
falls back to the plain kind label. A **post-run** row carries its run's distance and moving time,
looked up over the whole window in one query
([InboxController](../../app/Http/Controllers/InboxController.php#L102)), which is what the row's
distance/pace stat chips render.

## Grouped sections and the time toggle

Rows render grouped into **Today / This Week / Earlier**
([inboxBuckets.ts](../../resources/js/components/inbox/inboxBuckets.ts)), a pure client-side
grouping over whatever rows the window holds, no backend shape change. The "this week"
boundary is Monday-start, matching the backend's own week convention (`startOfWeek(Carbon::MONDAY)`,
e.g. [Periodizer](../../app/Services/Run/Plan/Periodizer.php#L56)) rather than a locale default.
`created_at` is a true instant, so bucketing reads it with plain `Date` parsing and deliberately does
not reuse `pace.ts`'s `mondayOf`, which is built for Strava's naive `start_date_local` values.

Tapping a row's timestamp toggles it between relative (`formatRelativeId`) and absolute
(`formatAbsoluteId`) display, per row, client-only state.

"Load older" is a real server round-trip, not a client-side reveal: it re-requests the page with a
wider `?shown=` and Inertia refetches only the list props, `preserveScroll` keeping what has already
been read where it was.

## Read state

Reading is per-row and idempotent: opening a deep link or replaying a celebration POSTs to
[NotificationReadController](../../app/Http/Controllers/Api/NotificationReadController.php#L19),
which is scoped through the user's own relation. The page marks the row read optimistically and
reloads only `unreadNotifications`, which is what the bell in
[MobileTopBar](../../resources/js/components/MobileTopBar.tsx) renders. There is no "mark all read": the unread count is a count of things not looked at, and a
button that lies about that is worse than a count that stays high.

`/inbox?item={id}` is the per-row deep link. The controller widens the window far enough to contain
that row ([InboxController](../../app/Http/Controllers/InboxController.php#L130)) so the target is on
screen even when it sits well behind the first twenty, and arriving on a row counts as reading it.

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
