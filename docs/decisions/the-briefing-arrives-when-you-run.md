---
title: The briefing arrives when you usually run
description: The daily briefing generated at 00:01 is pushed at the athlete's own usual start time, derived from the median of their last twenty runs, and the sweep that sends it adds no LLM call.
tags: [decision, notifications]
status: accepted
reviewed: 2026-09-10
code_refs:
  - app/Console/Commands/Notifications/MorningBriefingPushCommand.php
  - app/Services/Notifications/UsualRunTime.php
  - app/Notifications/MorningBriefingNotification.php
  - app/Services/Notifications/ChannelRouter.php
  - routes/console.php
---

# The briefing arrives when you usually run

**Status:** Accepted (decided 2026-09-10)

## Context

`ai:daily-briefing` narrates every active athlete's briefing at 00:01
([routes/console.php](../../routes/console.php#L39)), and then nothing tells them it exists. The
briefing is written for the morning they are about to have, and it sat on the dashboard waiting for
someone to open the app — which, for an athlete who opens Temari *after* a run, is the one moment
the briefing is already spent.

A fixed morning push would be the obvious fix and the wrong one: this app's athletes start at
05:00, at 06:30 and at 19:00, and a 06:00 push is an alarm clock for the first, late for the second
and meaningless for the third. Asking during onboarding was rejected too — the time someone types
is the time they *intend* to run, and the whole value of the push is landing at the time they
actually do.

## Decision

**The slot is the median start time of the athlete's last twenty runs.**
[UsualRunTime](../../app/Services/Notifications/UsualRunTime.php#L34) reads `start_date_local` off
those runs as a wall clock, converts each to minutes since midnight, and takes the median. The
median rather than the mean, because one 21:00 night race drags an average by half an hour and
leaves the median untouched. A count-based window rather than a fixed number of days, because at
three runs a week a 28-day window leaves a light runner permanently under the five-sample floor and
therefore permanently on the fallback; twenty runs is roughly seven weeks for that athlete and
roughly two for a daily one. Under five samples the answer is a flat **06:00**, which is a sane
default rather than a derived one and is honest about being one.

**One command, every fifteen minutes, matching buckets.** Rather than scheduling per athlete —
which the scheduler cannot express — `briefing:morning-push` runs on the quarter hour and sends to
the athletes whose slot rounds into the bucket it is in
([MorningBriefingPushCommand](../../app/Console/Commands/Notifications/MorningBriefingPushCommand.php#L27)).
Fifteen minutes is the granularity a running habit actually has; a minutely sweep would be 96×
the ticks to land the same push.

**It sends, it never generates.** The briefing row exists and is `done` by 00:01, hours before any
plausible slot. A row that is pending, failed or missing is **skipped** — the push is not a reason
to spend a token, and 00:01 plus `ai:self-heal` already own recovery. So this surface adds
**no new LLM call**: it appears in no origin table in [[llm-triggers]] because it dispatches
nothing, and its cost is one indexed row read per athlete per matching bucket.

**Push only, and idempotent on the briefing row.** The briefing is already on the dashboard, so an
inbox row of it would be a record of nothing new; what this adds is the timing, which is exactly
what a lock screen is for. `ChannelRouter::pushOnly()`
([ChannelRouter](../../app/Services/Notifications/ChannelRouter.php#L79)) is the routing answer, and
`MorningBriefingNotification::deliveryKey()` returns the briefing's id, so the shared
per-(analysis, channel) claim ([[inbox-is-an-always-on-channel]]) is already one claim per athlete
per day. No new claim table.

**No toggle of its own.** It rides the existing opt-in: the channel-neutral master switch for
*whether*, the push mute for *where*. Settings deliberately holds one switch rather than a per-type
list, so the switch's own description names this alongside everything else it governs rather than
growing a fourth row.

## Consequences

- An athlete who runs at 05:40 is pushed at 05:30; one at 06:35 at 06:30. The bucket is the
  athlete's habit rounded down, never their exact minute.
- An athlete with fewer than five ingested runs is pushed at 06:00 until their history says
  otherwise, and moves on its own once it does — nothing to configure and nothing to migrate.
- A day whose briefing failed is a day with no push, silently. That is deliberate: the failure is
  already visible where failures live ([[bounded-self-heal-and-dead-letter]]), and a push saying
  nothing would be worse than no push.
- The median moves as the athlete does. Someone who switches from evenings to mornings is followed
  within about ten runs rather than being asked to update a setting they have forgotten exists.

## See also

[[llm-triggers]] — the gate this note reports to; nothing here reaches `StructuredChatCaller`.
[[notification-inbox]] · [[scheduler]] · [[inbox-is-an-always-on-channel]]
