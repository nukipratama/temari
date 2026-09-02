---
title: Past You engine
description: How "you vs your own comparable history" is matched, scored and turned into a trend verdict
tags: [architecture, run]
status: living
reviewed: 2026-08-13
code_refs:
  - app/Services/Run/Story/PastYouMatcher.php
  - app/Services/Run/Story/PastYouTrendBuilder.php
  - app/Services/Run/Story/PastYouComparison.php
  - app/Services/Run/Story/ComparableRun.php
  - app/Services/Run/Story/PastYouTrend.php
  - app/Enums/TrendVerdict.php
  - app/Enums/TrendDirection.php
---

# Past You engine

**You vs Past You.** Every run is measured against the runner's own comparable
history. There is no leaderboard, no cohort, no cross-user read anywhere in this
path: it is the product premise and a Strava platform requirement at once.

## Matching reads summary fields only

[ComparableRun](app/Services/Run/Story/ComparableRun.php) projects an
`ActivityDetail` onto the fields `/athlete/activities` already returns: distance,
moving time, pace, average HR, elevation gain, plus the clock time and month
derived from `start_date_local`. Nothing in the matching path touches streams,
splits, `stream_summary`, TRIMP or weather, so a run still queued for lazy
hydration is a valid candidate on **both** sides of a comparison. See
[[run-ingest-pipeline]] for what `summary` vs `detailed` state carries.

That is why season stands in for temperature. The old temperature gate reads
`weather_temp_c`, which the detail pipeline fills; keeping it on the trend path
would have silently disqualified every un-hydrated run.

[PastYouMatcher::similarity()](app/Services/Run/Story/PastYouMatcher.php) applies
the hard rules first (same pace band, distance within 500 m, 21 to 365 days
apart, elevation density within 15 m/km when both sides know it) and then scores
the survivors on distance, average HR, elevation density, time of day and season.
An axis neither run can answer is dropped and the remaining weights renormalise,
so a summary-only pairing is not penalised for being summary-only.

**Pace is deliberately not a similarity axis.** The pace band already establishes
that two runs are the same kind of session; the pace gap *within* the band is the
signal the verdict measures. Scoring similarity on it would bury the change the
engine exists to find. Average HR is scored, but softly and without a rejection
threshold, for the same reason.

## Two selections on one rule set

- `findMatch()` serves the run-detail hero and prefers the **oldest** qualifying
  run, so the contrast reads as progress. Its temperature gate stays.
- `bestMatch()` serves the home-screen trend and prefers the **most similar**
  run, ties to the older one, so the deltas feeding the verdict are not noise
  from a poorly comparable pairing.

## The verdict

[PastYouTrendBuilder](app/Services/Run/Story/PastYouTrendBuilder.php) takes the
runner's last `WINDOW_DAYS` of runs, matches each against history from *before*
that window, and keeps between `MIN_COMPARISONS` and `MAX_COMPARISONS` pairs as
the evidence. A past run is used at most once, so the pairs are independent.

Each pair gets a [TrendDirection](app/Enums/TrendDirection.php) from
[PastYouComparison::direction()](app/Services/Run/Story/PastYouComparison.php):
pace decides once the gap clears the noise band, and heart rate decides when pace
came back flat, so holding pace at a higher HR reads as a loss rather than as "no
change".

A [TrendVerdict](app/Enums/TrendVerdict.php) is only called when the pairs agree
on a direction **and** the aggregate points the same way. One lopsided pair
therefore cannot be outvoted by a majority of tiny gains, and a majority of tiny
gains cannot be sold as improvement. Everything else is `plateaued`.

`not_enough_history` is a fourth, first-class outcome, not an error and not a
fabricated verdict: fewer than two comparable pairs in the window. It is what the
brand set's `no-past-match` empty state renders, and
[PastYouTrend](app/Services/Run/Story/PastYouTrend.php) still carries the single
pair it did find so the empty state can say how close the runner is.

## Supporting readings degrade, they do not gate

`fitness_delta_ctl` and `pace_consistency_now` / `_then` come from
[TrainingLoad](app/Services/Run/Metrics/TrainingLoad.php) and
[StreamSummary](app/Services/Run/Metrics/StreamSummary.php) +
[PaceConsistency](app/Services/Run/Metrics/PaceConsistency.php). Both need the
detail pipeline, so both are null on a purely summary-state window. They sit
beside the verdict; they never change it. `relative_effort_band` sat here too
until `W2`: P18 cut relative effort's UI and the payload field outlived its
reader by a wave. [RelativeEffort](app/Services/Run/Metrics/RelativeEffort.php)
itself is untouched, still feeding three narrators via `EffortContextTool`.

## Open product questions

- `WINDOW_DAYS` is 42, chosen to line up with the CTL time constant already used
  as this codebase's fitness horizon. 28 and 90 are equally defensible and the
  choice has not been made by product.
- ~~What the UI *says* for `not_enough_history`~~ — settled in the home-screen
  rebuild: `comparison_count` 0 and 1 get different copy, and a single pair is
  still rendered as evidence so a near miss reads as one. See [[dashboard]].

## See also

- [[run-ingest-pipeline]] — what a `summary` vs `detailed` run carries
- [[training-load-metrics]] — the CTL/ATL series the fitness reading is read off
- [[run-detail]] — the hero panel that uses `findMatch()`
- [[dashboard]] — the home screen that renders the verdict
