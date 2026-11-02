---
title: The briefing arrives an hour before you run, and has its own switch
description: The morning briefing push goes out an hour before the athlete's usual start on that weekday, with its own on/off switch and an optional fixed send time in Settings; supersedes the timing and no-toggle parts of the-briefing-arrives-when-you-run.
tags: [decision, notifications]
status: accepted
reviewed: 2026-11-02
code_refs:
  - app/Console/Commands/Notifications/MorningBriefingPushCommand.php
  - app/Services/Notifications/UsualRunTime.php
  - app/Notifications/MorningBriefingNotification.php
  - app/Models/NotificationPreference.php
  - app/Http/Requests/UpdateNotificationPreferenceRequest.php
  - resources/js/pages/Settings/Index.tsx
---

# The briefing arrives an hour before you run, and has its own switch

**Status:** Accepted (decided 2026-11-02, issue #2351 decision 5)

Supersedes two parts of [[the-briefing-arrives-when-you-run]]: the slot (the median start of the
last twenty runs, floored to the quarter hour) and "no toggle of its own". The fifteen-minute
sweep, the 60-minute catch-up, the one claim per athlete per channel per day, holding a slot inside
quiet hours until 04:00, and "it sends, it never generates" all stand.

## Context

The push landed *at* the athlete's usual start, which is the moment they are lacing up, not
reading. And one median over every run is wrong for an athlete with two habits. The owner runs some
mornings between 05:38 and 06:51 and some evenings. The overall median fell at about 06:45, after
most of the morning starts it was meant to precede.

## Decision

**An hour before the usual start.** `MorningBriefingPushCommand::LEAD_MINUTES` is 60. The slot is
the usual start minus the lead, floored to the quarter hour, and never before midnight
(`MorningBriefingPushCommand::slotFor`). The briefing's two-hour stale cutoff runs from that slot.

**The usual start is today's weekday first.** [UsualRunTime](../../app/Services/Notifications/UsualRunTime.php)
takes the median start of the athlete's last `WEEKDAY_SAMPLE_SIZE` (10) runs on today's weekday,
when there are at least `MIN_SAMPLES` (5) of them. Otherwise it falls back to the existing median of
the last `SAMPLE_SIZE` (20) runs, and then to 06:00. Both numbers come from the existing window:

- The floor is the same five samples, for the same reason: below that, a median is one habit and
  two accidents.
- The window is half the overall one. A weekday someone runs on once a week fills ten samples in
  ten weeks, and a changed habit takes over the median within about five. A twenty-run window per
  weekday would take about ten weeks to follow a change, against about ten runs today.

The date-keyed daily cache stays, and its key now also fixes the weekday.

**Its own switch, under the master one.** `notification_preferences.briefing_push_enabled`
(default on) turns this push off alone. The master switch, `notifications_enabled`, still gates it
too. The sweep skips an athlete with either one off, and `MorningBriefingNotification::via()`
re-checks both at delivery. Settings shows the switch under **keep me posted**, greyed out while
the master switch is off.

**An optional fixed send time.** `briefing_push_minute`, in minutes since local midnight, applies
to every day and replaces both the lead and the median. Null means automatic. It is floored to the
quarter hour like an automatic slot, so 06:10 goes out on the 06:00 tick, before rather than after.
It must fall before quiet hours begin (00:00 to 21:59). A slot from 22:00 is held past midnight,
and since a held send is judged stale as of when it was held, it would deliver the previous day's
briefing at 04:00. The cap narrows this rather than closing it: a slot from 21:00 still has
catch-up ticks after 22:00, so a briefing that turns `done` late, or a channel wired after the
first send, can still be held into the next morning, as an automatic slot that late already could. Settings saves the time when the field is left, puts back a time from 22:00, and
offers **clear** to return to automatic.

## Consequences

- The owner's mornings get the push at about 04:45 to 05:45, depending on the weekday, rather than
  at 06:45.
- An athlete with fewer than five runs is pushed at 05:00, an hour before the 06:00 fallback start.
- A usual start before 01:00 gives a 00:00 slot, which runs before the 00:01 generation. The
  catch-up window already covers this, as it did for the old 00:00 bucket.
- An early slot inside quiet hours is held until 04:00 as before. With the lead, more athletes
  have one: a usual start before 05:00 now gives a slot before 04:00.
- Two new columns on `notification_preferences` and two new fields on the
  `PATCH /profile/notifications` payload, which still always carries the full state.

## See also

[[the-briefing-arrives-when-you-run]] · [[the-briefing-also-goes-to-telegram]] ·
[[quiet-hours-hold-every-notification]] · [[notification-delivery]] · [[settings]]
