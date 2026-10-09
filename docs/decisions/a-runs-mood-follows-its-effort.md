---
title: A run's mood follows its effort
description: When no other rule picks a mood, a run's mood follows the same effort scale as its colour, so a steady or hard run never gets the rest-day chill mood.
tags: [decision, run, design]
status: accepted
reviewed: 2026-10-02
code_refs:
  - app/Services/Run/Story/Temari.php
---

# A run's mood follows its effort

**Status:** Accepted (2026-10-02). Decided in #1527.

## Context

[Temari::moodForActivity()](app/Services/Run/Story/Temari.php) picks a mood from first-match rules: a PR, a controlled hard session, decoupling, heat, a hard grind. A run that matched none fell through to chill, the rest-day mood, even when the run's effort colour said steady or hard. The card then showed a hard-effort colour beside a face that said it was a rest.

## Decision

The fallback follows [RunEffort](app/Services/Run/Metrics/RunEffort.php), the source of the effort colour: Hard maps to blazing, Steady to easy, anything else to chill. Earlier rules still win. **Product choice**: a mood is a presentation of the run, and two cues on one card must not contradict each other.

## Consequences

- Colour and mood agree on every run the earlier rules do not claim.
- Easy and unknown-effort runs keep chill.
- No training claim is made; the mood is a label, not a verdict.
