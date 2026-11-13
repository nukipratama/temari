---
title: The briefing send time is a quarter hour
description: A fixed briefing send time must fall on :00, :15, :30 or :45; the picker snaps to the quarter hour before and the server refuses any other minute, so the time shown is the time it sends. Supersedes the silent floor in the-briefing-arrives-an-hour-before-you-run.
tags: [decision, notifications]
status: accepted
reviewed: 2026-11-13
code_refs:
  - app/Http/Requests/UpdateNotificationPreferenceRequest.php
  - resources/js/components/settings/BriefingSendTime.tsx
  - database/migrations/2026_11_13_000000_floor_briefing_push_minute_to_quarter_hour.php
---

# The briefing send time is a quarter hour

**Status:** Accepted (decided 2026-11-13)

Supersedes one part of [[the-briefing-arrives-an-hour-before-you-run]]: a chosen send time off the
quarter hour is no longer stored and silently floored. The fifteen-minute sweep, the automatic slot
and everything else there stand.

## Context

The Settings field hinted quarter hours with `step={900}`, which iOS ignores, so the owner saved
05:10 and every briefing arrived at 05:00. The time shown was not the time it sent.

## Decision

**Only quarter hours are a send time.** `UpdateNotificationPreferenceRequest` requires
`briefing_push_minute` to be a multiple of `MorningBriefingPushCommand::BUCKET_MINUTES`.
`BriefingSendTime` snaps a typed time to the quarter hour at or before it when the field is left,
and shows the snapped time, so what Settings shows is when the push goes out.

**Not a minutely sweep.** Honouring any minute would mean running `briefing:morning-push` every
minute: 1,440 run-log rows a day where `schedule:heartbeat` is kept out of that log for the same
reason, and a lock expiry to retune. A quarter hour is the granularity the automatic slot already
has.

**Saved times are floored once.** The migration floors each stored off-quarter time to the quarter
hour the sweep was already sending it on, so no push moves.

## See also

[[the-briefing-arrives-an-hour-before-you-run]] · [[the-briefing-arrives-when-you-run]] · [[settings]]
