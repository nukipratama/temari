---
title: History narrates on demand
description: Pre-connect runs are hydrated but filled rule-based on ingest; an LLM read is requested by the athlete on the run's own page, and only once every older run in the backfill window has hydrated.
tags: [decision, ai, cost]
status: accepted
reviewed: 2026-09-15
code_refs:
  - app/Services/AI/HistoryNarrationGate.php
  - app/Listeners/DispatchPostRunAnalysis.php
  - app/Http/Controllers/Api/AnalysisController.php
  - app/Services/AI/BackfillAgeGate.php
  - app/Services/AI/RuleBased/RuleBasedNarrationFiller.php
---

# History narrates on demand

**Status:** Accepted (documented 2026-09-15)

## Context

A first Strava connect imports the athlete's history, and the ingest cascade narrated every run of
it that fell inside the 84-day backfill window: `CardFlavor`, `PostRunSpeech` and `RunInsight`, three
model calls per run. Measured, that is roughly 13k tokens per run and around 2.5M tokens for one
signup.

All three surfaces render on one page — the run's own detail page. Everything the rest of the app
structurally needs from a historical run (splits, TRIMP, PRs, zones, weekly aggregates, the plan's
volume anchor) is deterministic and free. So the backfill was paying, up front and for every run,
for prose that is read only if someone opens that specific run, which most historical runs never
are.

[[twelve-week-narration-cutoff]] already handles the far end of this: past 84 days there is no LLM
narration at all. This is the same argument applied one step nearer.

## Decision

A run that started before the athlete's Strava connection is *history* — it exists only because the
first-connect backfill imported it. `HistoryNarrationGate::isHistorical()` says so, anchored on
`strava_connections.created_at`, the same anchor [[recap-waits-for-hydration]] uses.

On ingest, a historical run takes the path a too-old run already takes: streams and metrics hydrate
exactly as before, and its three narration rows are filled by `RuleBasedNarrationFiller` and marked
`Done`. No LLM job is dispatched for it. Runs after the connect are untouched — the webhook cascade
narrates them automatically, oldest link first, exactly as today.

The LLM read of a historical run is the per-block **"Try again"** on that run's page:
`AnalysisController::trigger()` invalidates the rule-based row and narrates it properly. That is the
existing manual path, with its existing cooldown, rate limit, ceiling and chain resumption — nothing
new renders, and nothing new is billed without a click.

That trigger waits on hydration. `HistoryNarrationGate::awaitsHydration()` refuses it with **409**
while any *older* run inside the backfill window is still `Activity::awaitingHydration()`. The
per-run narrators read resolved metrics through `get_recent_runs` and `get_recent_baseline`, and
narration is requested `invalidate: false`, so a read taken over a half-hydrated history writes a
thin story that nothing ever re-narrates. `strava:hydrate-backlog` drains newest-first, so the wait
is bounded by the drain reaching the older end of the window.

## Consequences

- A fresh signup's history costs the backfill nothing in tokens. The steady-state per-run spend is
  unchanged.
- A historical run's page shows rule-based narration until the athlete asks for a real read. It is
  never empty, and the ask is one click on the control that already exists.
- The refusal is a 409, the same status the paused-generation gate returns, so the UI's existing
  error path covers it without a new affordance.
- The gate is per athlete and per run: a run with nothing older than it left to hydrate reads
  immediately, even mid-drain.
- An athlete whose Strava connection row is gone has no anchor, so nothing of theirs is historical.
  Runs only exist because a connection ingested them, and revocation keeps the row.

## Alternatives considered

**Dispatch the LLM read when the page is opened, rather than on "Try again".** Rejected here: a page
open is not a request for a billed call, and a scroll through history would spend on every run
passed. The manual trigger is an explicit ask, already rate-limited and already understood.

**Narrate history lazily but eagerly re-narrate once hydration completes.** Rejected: that is the
2.5M tokens again, deferred by a few hours.

See [[twelve-week-narration-cutoff]] · [[recap-waits-for-hydration]] ·
[[background-hydration-drain]] · [[chained-narration]] · [[per-block-manual-retry]]
