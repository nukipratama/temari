---
title: Narration spends only on active athletes
description: Every LLM narration path, including a synced run's cascade, the recap kickoffs and self-heal, spends only on an athlete who opened the app in the last 7 days; the first visit after a gap catches them up, and one verdict ranks every reason a run is kept from the LLM.
tags: [decision, ai, cost]
status: accepted
reviewed: 2026-09-17
code_refs:
  - app/Services/AI/NarrationEligibility.php
  - app/Services/AI/NarrationVerdict.php
  - app/Actions/AI/RecentlyActiveUsers.php
  - app/Http/Middleware/StampLastSeen.php
  - app/Jobs/AI/NarrateOnReturnJob.php
  - app/Listeners/DispatchPostRunAnalysis.php
  - app/Services/AI/SelfHealer.php
  - app/Actions/AI/KickoffWeeklyRecaps.php
  - app/Actions/AI/KickoffMonthlyRecaps.php
  - app/Http/Controllers/Api/AnalysisController.php
  - app/Services/AI/AnalysisService.php
---

# Narration spends only on active athletes

**Status:** Accepted (documented 2026-09-17). Supersedes [[narration-follows-the-athlete-not-the-run]].

## Context

[[narration-follows-the-athlete-not-the-run]] moved the scheduled cadences onto `last_seen_at`, but
only those. A synced run still paid for its card flavor, post-run speech, run insight, a briefing
refresh, the profile voice and Temari's read of the day, whether or not the athlete ever opened the
app. The weekly and monthly recap kickoffs filtered only the demo account, and the hourly self-heal
resumed any athlete's stalled rows, so a pending recap or run staged for an absent athlete was billed
within the hour anyway.

The run's narration was also gated twice, by `BackfillAgeGate` and `HistoryNarrationGate`, combined
as a bare OR on ingest and as ordered branches with different outcomes on a manual trigger. Adding
inactivity as a third reason would have meant a third combination rule.

## Decision

**Active** means `last_seen_at` inside the last 7 days, demo excluded, read from
`RecentlyActiveUsers`. No new signal: a Telegram interaction does not count. Deterministic work
(plan rows, compliance scoring, metrics, snapshots, recap row staging) still runs for everyone.

**One verdict.** `NarrationEligibility` returns a `NarrationVerdict` with a fixed precedence: demo,
too old, pre-connect, awaiting backlog, inactive. It has two entry points because the two call sites
ask different questions about age: the ingest cascade checks the run's date, while a manual trigger
keeps `blocksManualTrigger()`'s exhaustive per-type match, where a chained type resumes the chain
instead of going rule-based. A manual trigger never gets `Inactive` (the click is the athlete using
the app) and never gets `PreConnect` (hydrated history narrates on demand, see
[[history-narrates-on-demand]]). Each call site matches every case, so a new reason cannot be
dropped by one of them.

**While away.** A run synced for an inactive athlete makes no LLM request: its per-run rows are staged
`Pending`, and the briefing refresh, profile voice, clamp voice and Temari's read are skipped. The
recap kickoffs and every self-heal family draw from `RecentlyActiveUsers`, so nothing pending is
billed in the background.

**The return.** `StampLastSeen` notices the first visit after the window lapsed and queues
`NarrateOnReturnJob`; the request itself does no narration work. The job sends pending runs from the
last 7 days (and their cards), the latest closed week's and month's recaps, and this week's credited
day reads to the LLM, and fills older pending runs, cards and recaps rule-based. A `Failed` row is
left failed so its dead-letter stays visible. The job is idempotent by construction: stamping
happens once per day, every request is `invalidate: false`, and every fill skips a `Done` row.

**No push on return.** The job stamps `AnalysisOrigin::Return`, which rides the queued jobs and any
chain successors, and `AnalysisService::markDone()` never notifies for that origin. The athlete is
already in the app to read it. This does not rely on the notification age gate.

An account younger than the window is not a return: a first visit has nothing deferred, and its
first-connect backfill owns its history.

## Consequences

- Manual Reread and Try again, the cost ceilings, the backfill age gate and the pre-connect gate are
  unchanged, and the demo stays rule-based.
- The briefing's recent runs only include runs whose post-run speech is `Done`, so a returning
  athlete's briefing can predate the return narration it would otherwise quote.
- A fresh run that syncs just after a return and joins a still-running return chain is narrated under
  the return origin and sends no push.
- The metering table gains a `return` origin, so return spend is attributable.

## Alternatives considered

**Narrate everything on return with the LLM.** Rejected: a month away would bill a month of runs in
one visit for prose the athlete scrolls past; the newest week is what gets read.

**Leave return catch-up to the hourly self-heal.** Rejected: it would bill every pending run with
the LLM regardless of age, push notifications for narration the athlete is already looking at, and
start up to an hour late.

See [[narration-follows-the-athlete-not-the-run]] · [[history-narrates-on-demand]] ·
[[twelve-week-narration-cutoff]] · [[bounded-self-heal-and-dead-letter]] · [[demo-user-billing-exclusion]]
