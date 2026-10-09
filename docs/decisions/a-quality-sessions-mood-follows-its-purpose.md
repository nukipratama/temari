---
title: A quality session's mood follows its purpose
description: On a planned quality day a run's mood is judged by what the session was for, a too-hard grade on any plan day reads overloaded, and an eased day reads as what it was eased to.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-02
code_refs:
  - app/Services/Run/Story/Temari.php
  - app/Services/Run/Plan/PlannedSessionTypes.php
  - app/Listeners/DispatchPostRunAnalysis.php
---

# A quality session's mood follows its purpose

**Status:** Accepted (2026-10-02). Builds on [[a-runs-mood-follows-its-effort]], which it does not replace.

## Context

A planned tempo with 27% of its time in Z4 read `easy`. It had no negative split and no drift reading, so it fell through to the steady-effort fallback that [[a-runs-mood-follows-its-effort]] maps to `easy`. A negative-split tempo read `easy` too. The mood vocabulary ([TemariPersona](app/Services/AI/TemariPersona.php#L27)) defines `blazing` as a session the athlete clearly went after and `easy` as light aerobic running, so a tempo run as a tempo was given the wrong word. The plan day's grade, which judges the session by pace against the advice shown, landed after the mood was written and was never read. An eased tempo still coloured and read as a tempo.

## Decision

[Temari::moodForActivity()](app/Services/Run/Story/Temari.php) reads the run's plan day when the run is that day's only run, using the day's effective type:

- **A quality day is judged by its purpose.** Tempo, intervals, a race, or a long run with a marathon-pace block reads `blazing` when it was graded as done as asked, or, ungraded, when the run shows threshold work. *Evidence-supported:* a quality session's purpose is sustained near-threshold work under control (Daniels; Seiler's intensity distribution). A negative split or even pacing on such a day is good execution, not an easy run.
- **A too-hard grade on any plan day reads `overloaded`**, so the mood agrees with the plan's "ran too hard" note. *Evidence-supported:* easy days run too hard are the common error polarized training warns against (Seiler 2010).
- **A missed quality session keeps the honest mood of what was run**, and heat, drift and PRs keep their precedence.
- **An eased day reads as what it was eased to**, for the effort colour and the mood alike ([PlannedSessionTypes](app/Services/Run/Plan/PlannedSessionTypes.php)). *Product choice.*
- Runs on a day with no plan, or with two runs, keep the existing rules. *Product choice:* without a plan, "intended hard" is inferred from the same zone share the grind rule reads.

Ingest writes the mood before the day is graded, so [DispatchPostRunAnalysis](app/Listeners/DispatchPostRunAnalysis.php) re-reads an existing mood right after grading and before any narration is requested, keeping the narration fingerprint consistent.

## Consequences

- New runs get the purpose-based mood on ingest. Stored moods of past runs change only when `RecomputeCardClaimsCommand` (removed 2026-10-08) runs for an athlete; that recomputes moods and PR flags, writes no grade and requests no narration.
- A changed mood changes the run's material fingerprint, so a run whose narration later re-runs reads the new mood.
