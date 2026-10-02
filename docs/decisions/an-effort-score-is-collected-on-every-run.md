---
title: An effort score is collected on every run
description: Any run, at any age and with or without heart rate, can take an optional CR-10 effort score stored with the time it was entered; the score changes no load until #1577 decides what coaching does with it.
tags: [decision, run]
status: accepted
reviewed: 2026-10-02
code_refs:
  - app/Http/Controllers/PerceivedEffortController.php
  - app/Http/Controllers/DashboardController.php
  - resources/js/components/run/EffortScore.tsx
  - resources/js/lib/perceivedEffort.ts
---

# An effort score is collected on every run

**Status:** Accepted (2026-10-02). Built in #1554 after the owner's scope change on that issue. Leaves [[unscored-load-is-null-not-zero]] unchanged: a run without heart rate is still unscored for load.

## Context

Session RPE (the athlete's rating of the whole session times its duration) is a validated, mode-independent load measure ([[coaching-evidence#Foster2001]], [[coaching-evidence#Haddad2017]]), and in runners it tracks the same training response as TRIMP ([[coaching-evidence#Wallace2014]]). How the app should use it (a load for runs without heart rate, a check on heart-rate load, or neither) is not settled. Deciding that well needs the athlete's own feel next to their heart-rate data first.

## Decision

**The athlete may rate how hard any run felt, on Foster's CR-10 scale. The score is stored and changes nothing else.**

- **Every run, any age.** Runs with and without heart rate both take a score, and a score can be set, changed or cleared at any time. There is no window.
- **Stored with its entry time.** `activity_details.perceived_effort` (1–10) and `perceived_effort_at`, set whenever a score is saved or changed and cleared with it ([PerceivedEffortController.php:22](app/Http/Controllers/PerceivedEffortController.php#L22)). The timestamp lets later analysis weight or exclude a score entered long after the run.
- **No load effect.** TRIMP, long-term and short-term load, load balance, weekly snapshots, trends, plan inputs, grading and narration are untouched. Saving a score writes the two columns only.
- **Own runs only.** The endpoint answers 404 for another athlete's run, the same as the run page.
- **Where it is asked.** The run page offers the picker under the hero until the run has a score, then shows a chip beside the mood chip with "change" and "clear". Today does the same for the athlete's newest run ([DashboardController.php:73](app/Http/Controllers/DashboardController.php#L73)).
- **How it is drawn.** A hero number out of 10 with its CR-10 word, over a 1–10 slider drawn as ten segments: 1–4 leaf (easy), 5–6 citrus (steady), 7–10 ember (hard), Seiler's session-RPE three-zone split ([perceivedEffort.ts](resources/js/lib/perceivedEffort.ts)). The bands are display only; the stored score stays 1–10. The words follow Foster's modified Borg CR-10 (1 very easy, 3 moderate, 5 hard, 7 very hard, 10 maximal).
- **The demo** gets the picker; the score is stored like any other and spends no tokens.

## Consequences

- **Enables:** a record of feel beside heart rate on the same runs, the input #1577 decides on and #1578 builds as a shadow-mode signal before anything reads it for coaching.
- **Costs:** a score asked for and then not used yet. The picker says nothing about load, so it promises nothing it does not do.
- **A score is subjective.** Two athletes' sevens are not comparable, which the app never does anyway: the only comparison is with the athlete's own past.

## See also

- [[coaching-evidence]] — the session-RPE sources behind #1577.
- [[unscored-load-is-null-not-zero]] — why a run without heart rate still carries no load.
