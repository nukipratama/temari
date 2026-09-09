---
title: A day is scored when it is run, and only ever upward
description: Compliance is persisted at ingest the moment a day is earned, rather than only at 00:09 the next morning — which is also what stops a late-arriving run being frozen out of its own day.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-07
code_refs:
  - app/Services/Run/Plan/ComplianceScorer.php
  - app/Services/Run/Plan/SessionMatcher.php
  - app/Listeners/DispatchPostRunAnalysis.php
  - app/Console/Commands/Run/ScoreComplianceCommand.php
  - app/Enums/PlannedSessionStatus.php
---

# A day is scored when it is run, and only ever upward

**Status:** Accepted (2026-09-07). Supersedes the *"this stays render-only"* clause of
[[today-credits-when-earned]]; the rest of that note stands unchanged.

## Context

[[today-credits-when-earned]] made a day still in progress gradable, but deliberately kept
the *persisted* verdict on the daily pass: **"the persisted verdict is written once, the
morning after… a mid-day credit cannot freeze a partial day into the column that
`PlanAdapter` reads back for adherence."**

That reasoning is still correct, and this note does not undo it. What it did not anticipate
is that *written once* has a second edge.

[ScoreComplianceCommand](app/Console/Commands/Run/ScoreComplianceCommand.php) selects only
rows still `Planned`, and runs at **00:09**. `strava:sync` polls **hourly**. So a run at
23:30 whose webhook is missed is judged `missed` at 00:09, ingested at 01:00, and then
**never re-judged** — the row is no longer `Planned`, so nothing in the system will look at
it again. The wrong verdict is permanent, and
[PlanAdapter::previousWeekAdherencePct()](app/Services/Run/Plan/PlanAdapter.php) reads it
back the following Monday as though it were true, where it can pull a real week under
`MISSED_WEEK_ADHERENCE` and deload an athlete who trained.

The same rule that stops a partial day freezing low is what stops a frozen day being lifted.

## Decision

**A day is scored the moment a run lands on it, and the write only ever moves upward.**

[ComplianceScorer::creditIfEarned()](app/Services/Run/Plan/ComplianceScorer.php) runs from
the ingest listener ([DispatchPostRunAnalysis](app/Listeners/DispatchPostRunAnalysis.php)),
which both the Strava webhook and `strava:sync` already feed. It writes the verdict **only
when the day is credited**; anything short stays `Planned`. A second run the same day can
raise `done` to `overreached`, and never the reverse.

That asymmetry is the whole design, and it is the same one the render already uses — this
persists what the page was already showing, rather than inventing a second rule:

- **A partial day still cannot freeze low.** Short of credited, nothing is written at all.
- **A late arrival is corrected.** A run syncing after 00:09 finds a row that is no longer
  `Planned` and lifts it, which is the case nothing else could reach.
- **The daily pass still settles the days that ended short**, exactly as before.

`ComplianceScorer` is one class rather than two because the trailing-`HISTORY_WEEKS`
context window is load-bearing: scoring a lone week in isolation lets
[PlanRenderer::weekPhasesAndMultipliers()](app/Services/Run/Plan/PlanRenderer.php) read it
as an isolated week 1 and drop the Build ramp it is actually deep into, so every prescribed
km the verdict is measured against would be wrong. Two copies of that would drift.

## Consequences

- The persisted column now agrees with the card during the day, instead of trailing it
  until the next morning.
- A day the athlete earned is durable immediately, so a page load is no longer the only
  thing that knows about it.
- One extra scoring pass per ingested activity. It early-returns on the first query when
  the date has no `PlannedSession` row, which is every run predating the athlete's plan and
  therefore most of a backfill.
- `ran_anyway` on a past rest day is now recorded at ingest rather than the next morning.
- An excused day is still never touched: `Skip` is not credited, so the upward guard
  refuses it before anything is written.

## Alternatives considered

**Re-score fully at ingest, up or down.** The column would always reflect reality, at the
cost of persisting `missed` at 07:00 on a day with fourteen hours left in it — precisely
what [[today-credits-when-earned]] refused, and for a reason that has not changed.

**Only fix the late arrival, leave today alone.** Narrower, needs no new ADR, and kills the
defect. Rejected because it leaves two rules for one question — the render crediting today
while the column waits for morning — and the extra rule buys nothing the upward-only write
does not already give.

**Re-score the whole week on every ingest.** Catches mid-week corrections too, at several
writes per run and by reopening days already settled.
