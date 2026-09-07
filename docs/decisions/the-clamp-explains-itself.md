---
title: The clamp explains itself, in its own narration
description: A readiness step-down gets its own coarsely-fingerprinted LLM line, requested from the two sites that already compute a ceiling, replacing the templated note in place rather than leaving a skeleton.
tags: [decision, run, plan, ai]
status: accepted
reviewed: 2026-09-07
code_refs:
  - app/Services/AI/Narrators/PlanClampVoiceNarrator.php
  - app/Jobs/AI/AnalyzePlanClampVoiceJob.php
  - app/Services/Run/Plan/ClampNarrationContext.php
  - app/Services/AI/MaterialFingerprint.php
---

# The clamp explains itself, in its own narration

**Status:** Accepted (2026-09-07)

## Context

Since [[readiness-clamp-is-advisory]] a clamped day renders as a marked step-down *beside*
the session the plan asked for, explained by one of a handful of templated strings. The
strings are correct but generic: they name the ceiling's shape, never the athlete's
actual week, and they cannot say the most common reason of all — *you already ran today*.

## Decisions

### Its own `AnalysisType`, not a branch of `plan_day_voice`

The day's blurb is fingerprinted on the stored session. A clamped day is exactly the day
where readiness moves repeatedly, so folding the explanation into `plan_day_voice` would
re-bill the expensive blurb every time the ceiling shifted — on the one kind of day it is
least stable. `plan_clamp_voice` keeps its own row, its own fingerprint and its own
narrator, and `plan_day_voice` is untouched.

### Fingerprinted coarsely, on purpose

`MaterialFingerprint::forClamp()` digests the **ceiling band, the type it downgraded to,
and whether the athlete has already run** — not the exact ceiling, not distances. The
ceiling is recomputed on every ingest and drifts a little with each run logged; a
fine-grained fingerprint would bill this line several times a day. A ceiling that slides
within its own band changes nothing worth saying.

### Requested where the ceiling already exists, never from a render

The ingest listener and the 00:01 briefing both already compute a ceiling — the two places
[RestClampRecorder](app/Services/Run/Plan/RestClampRecorder.php) writes from, for the same
reason. A run landing is what moves the ceiling, so the event that invalidates this line is
the event that regenerates it, and the line is usually ready before the app is next opened.
The briefing covers a clamp that fires on carried-over fatigue with no run behind it.
**No LLM narration is dispatched from a GET anywhere in the plan path, and this does not
become the first.**

### The templated note stays as a permanent floor

`restNote()` / `easyOnlyNote()` always render, and the narration replaces them **in place**.
The payload therefore gains no new field: `clamp.note` is simply better once a line lands.
A step-down is never unexplained, there is no pending skeleton on a block that must always
say something, and a paused or cost-capped day reads correctly with no special case.

### One resolver, shared by requester and job

[ClampNarrationContext](app/Services/Run/Plan/ClampNarrationContext.php) resolves the facts
for both the sites that request the row and the job that later fills it. Sharing it is what
stops a row being requested for a clamp the job cannot find. It reads the ceiling **fresh**
rather than trusting what the requester saw, so a clamp that has since lifted resolves to
null and the row is retired as obsolete rather than failing forever.

`ReadinessClamp::downgradeFor()` is the general form of the existing `clampsToRest()`, added
for the same reason: a caller that needs only the outcome should not have to build a segment
list to learn it. Both share `requiredRank()` with `apply()`, so they cannot disagree about
what the ceiling permits.

## Consequences

- **Bounded to ~2 generations on a clamped day** rather than one per run, by the coarse
  fingerprint.
- **Zone-dependent**, like `trend_read`: the ceiling comes off `TrainingLoad`, which is
  TRIMP-derived and therefore zone-weighted.
- **The discriminator never reaches forward.** Unlike `plan_day_voice`, which can be asked
  for a day later this week, a clamp only ever exists for today.
- **No frontend change at all.** The clamp payload's shape is unchanged.
- A clamp that develops mid-day with no run and no app open still goes unrecorded for
  *compliance* — that gap is [[readiness-clamp-is-advisory]]'s and is unchanged here.
