---
title: An effort score gives a run without heart rate its load
description: A run with no heart rate can take an optional CR-10 effort score within 72 hours of its start, and that score becomes the run's TRIMP as RPE × moving minutes ÷ 2, feeding load only; heart rate always wins.
tags: [decision, run]
status: accepted
reviewed: 2026-10-02
code_refs:
  - app/Services/Run/Metrics/PerceivedEffort.php
  - app/Services/Run/Ingest/ActivityPipeline.php
  - app/Services/Run/Ingest/SummaryIngest.php
  - app/Http/Controllers/PerceivedEffortController.php
  - resources/js/components/run/EffortScore.tsx
  - resources/js/lib/perceivedEffort.ts
---

# An effort score gives a run without heart rate its load

**Status:** Accepted (2026-10-02). Decided in #1544 (question 2, option B), built in #1554. Narrows [[unscored-load-is-null-not-zero]] without changing it: a run nobody scored is still unscored.

## Context

Edwards TRIMP needs heart-rate minutes per zone, so a run without heart rate adds no load: it is unscored, neither counted nor zero ([[unscored-load-is-null-not-zero]]). A treadmill run without a strap, or a watch left off the wrist, leaves a gap in long-term and short-term load that understates the week.

Session RPE (the athlete's rating of the whole session times its duration) is a validated, mode-independent load measure ([[coaching-evidence#Foster2001]], [[coaching-evidence#Haddad2017]]), and in runners it tracks the same training response as TRIMP ([[coaching-evidence#Wallace2014]]).

## Decision

**The athlete may rate how hard a run without heart rate felt, on Foster's CR-10 scale, and that rating gives the run its load.**

- **The rule.** Load is `score × moving minutes ÷ 2` ([PerceivedEffort.php:24](app/Services/Run/Metrics/PerceivedEffort.php#L24)). The divisor puts a 1–10 rating on the scale of Edwards' 1–5 zone weights, so a run rated 6 for 40 minutes weighs the same 120 as 40 minutes in zone 3. The mapping is a heuristic.
- **Where it goes.** The load is written to the run's `trimp_edwards` in the one place that already resolves it ([ActivityPipeline.php:469](app/Services/Run/Ingest/ActivityPipeline.php#L469)), so it reaches TRIMP, long-term and short-term load, strain and monotony through the existing paths. Distance, weekly volume, grading, session intent and mood never read it: none of them read TRIMP.
- **Saving recomputes, never re-grades.** Saving, changing or clearing a score rewrites the run's summary through [recomputeSummary](app/Services/Run/Ingest/ActivityPipeline.php#L490), rebuilds its weekly snapshot forward, and marks the trend snapshots dirty from the run's date ([PerceivedEffortController.php:60](app/Http/Controllers/PerceivedEffortController.php#L60)). It does not call the compliance scorer or plan reconciliation, so no day is re-graded. A run with no stored streams (a manual or treadmill entry) still gets its load rewritten ([ActivityPipeline.php:443](app/Services/Run/Ingest/ActivityPipeline.php#L443)).
- **Clearing returns the run to unscored**, a null TRIMP, never a zero.
- **Heart rate wins.** A run that gains heart rate, for example through a resync, takes Edwards TRIMP; its stored score is kept but no longer counts ([PerceivedEffort.php:26](app/Services/Run/Metrics/PerceivedEffort.php#L26)). A summary-only row whose resync reports heart rate drops a score's load until hydration computes the real one ([SummaryIngest.php:107](app/Services/Run/Ingest/SummaryIngest.php#L107)).
- **A 72-hour window.** The prompt shows only on a run without heart rate within 72 hours of its start, measured from the UTC start when the run carries one ([PerceivedEffort.php:36](app/Services/Run/Metrics/PerceivedEffort.php#L36)); afterwards it is hidden and the endpoint refuses a new, changed or cleared score ([PerceivedEffortController.php:41](app/Http/Controllers/PerceivedEffortController.php#L41)). Keeping it to recent runs makes it a training-time input, not a way to rewrite old weeks. The window length is a heuristic.
- **Runs only, own runs only.** The app stores runs only, and the endpoint answers 404 for another athlete's run, the same as the run page.
- **How it is drawn.** A hero number out of 10 with its CR-10 word, over a 1–10 slider drawn as ten segments in the effort colours: 1–3 leaf (easy), 4–6 citrus (steady), 7–10 ember (hard), with the word in the matching `-ink` ([perceivedEffort.ts](resources/js/lib/perceivedEffort.ts), [EffortScore.tsx](resources/js/components/run/EffortScore.tsx)). That grouping is a display mapping only: the stored score stays 1–10 and nothing reads a band. Nothing is selected until the athlete moves the slider. The words follow Foster's modified Borg CR-10 (1 very easy, 3 moderate, 5 hard, 7 very hard, 10 maximal), with the in-between values filled.
- **The demo** gets the prompt; the score is stored like any other and spends no tokens.

## Consequences

- **Enables:** a strap-less run counts toward load the day it happens, so a treadmill week no longer reads as lighter than it was.
- **Costs:** `trimp_edwards` now holds an sRPE-derived number on a run without heart rate, so per-run TRIMP readers (the run's relative effort, the history timeline's intensity, the narration fingerprint) see it too. They all describe how hard the run was, which is what the athlete rated.
- **A score is subjective.** Two athletes' sevens are not comparable, which the app never does anyway: the only comparison is with the athlete's own past.

## See also

- [[training-load-metrics]] — the engine the load feeds.
- [[coaching-evidence]] — the sources and labels.
