---
title: Past You engine
description: How "you vs your own comparable history" is matched, scored and turned into a trend verdict
tags: [architecture, run]
status: living
reviewed: 2026-09-23
code_refs:
  - app/Services/Run/Story/PastYouMatcher.php
  - app/Services/Run/Story/PastYouTrendBuilder.php
  - app/Services/Run/Story/PastYouComparison.php
  - app/Services/Run/Story/ComparableRun.php
  - app/Services/Run/Story/PastYouTrend.php
  - app/Enums/TrendVerdict.php
  - app/Enums/TrendDirection.php
  - app/Enums/ComparisonMetric.php
---

# Past You engine

**You vs Past You.** Every run is measured against the runner's own comparable
history. There is no leaderboard, no cohort, no cross-user read anywhere in this
path: it is the product premise and a Strava platform requirement at once.

## Matching reads summary fields only

[ComparableRun](app/Services/Run/Story/ComparableRun.php) projects an
`ActivityDetail` onto the fields `/athlete/activities` already returns: distance,
elapsed time, pace, average HR and elevation gain. It also carries optional
weather temperature; the builder adds planned-session context from the run's
date. The matching path never touches streams, splits, `stream_summary` or
TRIMP, so a run still queued for lazy hydration is a valid candidate on **both**
sides of a comparison. See
[[run-ingest-pipeline]] for what `summary` vs `detailed` state carries.

Season remains a soft ranking axis. The trend path also uses `weather_temp_c`
as a hard gate when both runs have it; either missing value leaves the pair
eligible. Temperature may arrive after initial ingest, so a weather update
clears the daily trend cache and a pair may appear or disappear once that data
lands.

[PastYouMatcher::similarity()](app/Services/Run/Story/PastYouMatcher.php) applies
the hard rules first (same pace band, distance within 500 m, 21 to 365 days
apart, elevation density within 15 m/km when both sides know it, temperature
within 3°C when both runs have it, and matching planned session types when both
runs map to one). A run maps to its date's non-rest planned session only when it
is the only run that date. Missing intent falls back to the pace-band rule.

A pair must score at least 0.6 on the similarity axes: distance, elevation, time
of day and season. The same score ranks the candidates that pass, so selection
stays blind to the outcome. An axis neither run can answer is dropped and the
remaining weights renormalise, so a summary-state pairing is not penalised for
missing optional data.

**Neither pace nor heart rate is a similarity axis**
([similarity()](app/Services/Run/Story/PastYouMatcher.php#L276)). The pace band
already establishes that two runs are the same kind of session, and both readings
are what the verdict measures. Scoring similarity on them would bury the change
the engine exists to find, so two candidates that differ only in HR rank equally.

## Two selections on one rule set

- `findMatch()` serves the run-detail hero and prefers the **oldest** qualifying
  run, so the contrast reads as progress. Its temperature gate stays.
- `bestMatch()` serves the home-screen trend and prefers the **most similar**
  run, ties to the older one, so the deltas feeding the verdict are not noise
  from a poorly comparable pairing.

Both paths hand a caller the same [TrendDirection](app/Enums/TrendDirection.php)
call rather than a signed number alone:
[PastYouComparison::directionFor()](app/Services/Run/Story/PastYouComparison.php#L116)
is the rule shared by `bestMatch()`'s `PastYouComparison::direction()` and by
`findMatch()`. There is one direction rule, efficiency-led, described under
[The verdict](#the-verdict).

[findMatchContext()](app/Services/Run/Story/PastYouMatcher.php) is the
unsigned shape built on top of `findMatch()`: every delta travels as its own
magnitude plus its own `relation` word — `pace`/`time`:
`{seconds_per_km|seconds, relation: faster/slower/same}`; `hr`: `{bpm,
relation: higher/lower/same}`. Pace is banded at the same 2% pace signal the
direction rule uses
([paceRelation()](app/Services/Run/Story/PastYouComparison.php#L126)). Heart
rate has no signal band of its own any more, so its relation names the gap as
the card shows it: `same` only when it rounds to 0 bpm
([hrRelation()](app/Services/Run/Story/PastYouMatcher.php#L221)). A run can
therefore read "faster" and "higher" with a `flat` direction, which is exactly
the faster-at-a-proportionally-higher-HR case. `direction` travels alongside as
the overall call. It was originally the
`get_past_you` / `get_latest_past_you` tool payload, added by #1016 (a signed
`pace_diff_sec`/`time_diff_sec`/`hr_diff_bpm` plus a `direction` composite)
and reshaped by #1033 (the unsigned-plus-relation shape above) to stop a
narrator inverting a field's sign — on a mixed-signal pair (pace slower, HR
lower), a model would read the lower HR as the headline, decide the run was
better, and state the pace backwards to fit that story, overriding whatever
composite verdict it was handed. Both re-encodings still let a live check
catch the same inversion about half the time, because the model states the
comparison to fit a mood it built from the rest of the context regardless of
how the delta is shaped. **#1009's decision: no narrator states this
comparison at all.** `get_past_you` and `get_latest_past_you` are retired (see
[[llm-triggers]]'s Retired surfaces); `findMatchContext()` survives as a plain
data source `RunController` reads directly to render the fact line
[PastYouCard.tsx](resources/js/components/run/PastYouCard.tsx) shows on the
run-detail page, next to `PostRunSpeechNarrator`'s narration and never inside
it, built from the same `relation` words rather than a UI-side recompute of
direction. `PostRunSpeechNarrator` and `BriefingMascotVoiceNarrator`'s prompts
now forbid stating a comparison to a specific past run, numbers or direction
words alike.

## The verdict

[PastYouTrendBuilder](app/Services/Run/Story/PastYouTrendBuilder.php) takes the
runner's last `WINDOW_DAYS` of runs, matches each against history from *before*
that window, and keeps up to `MAX_COMPARISONS` qualifying pairs as evidence. A
past run is used at most once, so the pairs are independent.

Each pair gets a [TrendDirection](app/Enums/TrendDirection.php) from
[PastYouComparison::direction()](app/Services/Run/Story/PastYouComparison.php),
decided on one metric per pair
([metricFor()](app/Services/Run/Story/PastYouComparison.php#L84)):

- **Efficiency** when both runs carry an average heart rate and both are at least
  20 minutes of `elapsed_time` (`EF_MIN_ELAPSED_SEC`). Efficiency is whole-run
  average speed divided by average HR, from summary fields only. The pair counts
  as changed at a 3% efficiency difference.
- **Pace** otherwise, since a warm-up dominates a short run's average HR. The
  pair counts as changed at a 2% pace difference, relative to the past run's
  pace.

The thresholds live on
[ComparisonMetric::signalPct()](app/Enums/ComparisonMetric.php#L17). Pace alone
is weather-confounded and a few bpm sits inside wrist-sensor noise, which is why
neither absolute threshold survives. A pair that is faster at a proportionally
higher HR is `flat`, not better. There is no device gate: device bias is left to
the agreement rule below.

At least three qualifying pairs are needed for a verdict. Exactly two are shown
as an early read with no verdict; zero or one keep the existing empty state.
Up to four qualifying pairs are shown.

A [TrendVerdict](app/Enums/TrendVerdict.php) is only called when at least two
thirds of the pairs point the same way, no pair points the opposite way, and the
aggregate agrees. The aggregate reads each pair's change in multiples of its own
metric's threshold (efficiency change ÷ 3%, pace change ÷ 2%) and needs a window
mean of at least ±1 in the verdict's direction
([aggregateDirection()](app/Services/Run/Story/PastYouTrendBuilder.php#L221)), so
windows mixing efficiency and pace pairs average on one scale. Flat pairs are allowed. If any pair points the other way, the
verdict is `mixed`, a distinct state that keeps every row visible and states the
split. If the evidence is not mixed but does not meet the vote or aggregate
threshold, it is `plateaued`.

`not_enough_history` is a first-class early-read outcome, not an error or a
fabricated verdict: fewer than three qualifying pairs in the window. Two pairs
are rendered there with early-read copy, and
[PastYouTrend](app/Services/Run/Story/PastYouTrend.php) carries the pairs it did
find so the empty state can say how close the runner is.

Each pair ships its deciding `metric` and a `pace_relation`, and the trend ships
`verdict_metric` (`ef`, `pace`, or `mixed` when pairs used both), the mean
`pace_relation`, and an `hr_relation` that bands the mean HR shift at
`SAME_HR_BPM` (2 bpm, inclusive)
([verdictMetric()](app/Services/Run/Story/PastYouTrendBuilder.php#L236)). The
home copy reads those words rather than re-deriving thresholds; see
[[dashboard]].

## Built once per runner per day

The verdict is the most expensive thing the home screen computes: a year of the
runner's history matched pair-by-pair for four comparisons. Nothing in it moves
until a run lands, so
[PastYouTrendBuilder::payload()](app/Services/Run/Story/PastYouTrendBuilder.php)
memoizes the rendered array under `past-you-trend:{user}:{date}` and the home
controller reads that, never `build()` directly. The date in the key rolls the
entry over at midnight; a run landing earlier drops it through
[WeeklyAggregator](app/Services/Run/Metrics/WeeklyAggregator.php), which also
clears the training-load summary. A temperature change on an activity detail or
a planned session date or type change drops the cache too, since either can
change which pairs qualify. Ingest, backfill and the deleted-activity cleanup
still reach it through the aggregator call.

History is read as plain query records rather than `ActivityDetail` models —
nothing past [ComparableRun](app/Services/Run/Story/ComparableRun.php) touches
Eloquent, and hydrating a year of models cost an order of magnitude more than
the matching it fed.

The 407-day date range is what bounds that read; the row count beside it is only
a backstop against pathological data such as a duplicated import. It was 400,
which a busy year of running reaches, and because the query orders newest-first
it silently dropped the *oldest* rows — eligible candidates that `bestMatch()`
does not penalise for age, so a verdict could change with nothing saying so. It
is now 2,000, above the 814 runs a twice-a-day runner could log in the range.

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
