---
title: Weekly recaps resume when hydration completes
description: Drain settlement immediately re-offers closed hydrated weeks, while the hourly self-heal remains the fallback.
tags: [decision, ai, run, strava]
status: accepted
reviewed: 2026-09-21
code_refs:
  - app/Actions/AI/SettleEarlyNarrationAction.php
  - app/Actions/AI/KickoffWeeklyRecaps.php
  - app/Services/AI/SelfHealer.php
---

# Weekly recaps resume when hydration completes

**Status:** Accepted (2026-09-21)

## Context

[[recap-waits-for-hydration]] correctly held a weekly recap until its runs had hydrated, but left
pickup to the hourly self-heal. A drain that finished just after the sweep therefore left closed
weekly recaps Pending for almost another hour even though their data was ready.

## Decision

Drain settlement immediately invokes the existing per-user weekly kickoff
([SettleEarlyNarrationAction](app/Actions/AI/SettleEarlyNarrationAction.php):82). The kickoff remains
the single owner of closed-window filtering, hydration readiness, historical rule-based fills and
idempotent dispatch ([KickoffWeeklyRecaps](app/Actions/AI/KickoffWeeklyRecaps.php):47). The open week
therefore stays Pending by design, and the hourly self-heal remains a fallback rather than the
normal pickup path.

## Consequences

- Closed hydrated weeks resume as soon as the drain finishes instead of waiting up to an hour.
- No second billing path is introduced: a queued or Done row cannot be claimed again.
- The hydration gate, grace window and open-week rule from [[recap-waits-for-hydration]] remain
  unchanged; this decision supersedes only that ADR's pickup timing.

## See also

- [[deferred-recap-windowing]]
- [[chained-narration]]
- [[bounded-self-heal-and-dead-letter]]
