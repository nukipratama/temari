---
title: Easy and long runs are capped by heart rate, and quality paces bend to readiness
description: Easy and long runs are prescribed under the top of the athlete's zone 2, with pace as a hint, and judged from the stored heart-rate stream; the same rule defines a ragged day; at the mildest readiness triggers a quality session keeps its minutes at a 3% slower pace.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-05
code_refs:
  - app/Services/Run/Ingest/StreamAnalysis.php
  - app/Services/Run/Metrics/StreamSummary.php
  - app/Services/Run/Plan/EasyEffort.php
  - app/Services/Run/Plan/SessionIntentJudge.php
  - app/Services/Run/Plan/ComplianceScorer.php
  - app/Services/Run/Plan/PlanAdapter.php
  - app/Services/Run/Plan/ReadinessClamp.php
  - app/Services/Run/Plan/PlanRenderer.php
  - app/Services/Run/Plan/IntentOutcome.php
  - app/Models/RunnerProfile.php
  - resources/js/lib/plan.ts
---

# Easy and long runs are capped by heart rate, and quality paces bend to readiness

**Status:** Accepted (2026-10-05). Decision #1803, layer 5 of #1804. Partly supersedes [[a-day-is-graded-on-distance-and-intent]] (how an easy or long day is judged) and [[decoupling-describes-a-run-and-a-deletion-re-grades-its-day]] (what makes a day ragged). Heat is out of scope: no forecast, no heat nudge and no heat band, because a forecast cannot know when or where the athlete runs.

## Context

Easy and long runs were prescribed as a pace and judged on pace, with heart rate only able to rescue a run faster than marathon pace when no more than 20% of its zone time sat above Z2. A pace target on an easy day asks a tired, hot or hilly athlete to run harder than the day is for, and a share of zone time cannot tell a warm-up spike from a run held over the line for half an hour. Quality sessions at a mild readiness concern lost a quarter of their minutes, the same as at a strong one.

## Decision

1. **The cap.** An athlete whose zones come from Strava, a manual edit or an observed peak has easy and long runs prescribed under the top of zone 2, their `Z3` lower bound ([RunnerProfile::easyHrCapBpm()](app/Models/RunnerProfile.php#L92)). Config-default zones keep pace as the target. Zone derivation is unchanged. The cap is an estimate of the first ventilatory threshold, and fixed-percentage zones place individuals in different domains (coaching-evidence-base claim 24: Jamnick 2020, Mann 2013, Seiler 2010; [[coaching-evidence#Jamnick2020]]); that error likely exceeds any choice below.
2. **What the athlete sees.** The day's target reads "under {cap} bpm" and the pace becomes a hint, "about m:ss/km"; a long run's hint says the pace may slow late ([PlanRenderer::heartRateCapOf()](app/Services/Run/Plan/PlanRenderer.php#L536), [sessionHint()](resources/js/lib/plan.ts#L425), [targetLabel()](resources/js/lib/plan.ts#L625)). On a marathon-pace long run the block keeps its pace and the hint caps the easy running around it.
3. **The per-run figure.** Ingest stores, on each run's stream summary, the moving seconds after the first 5 minutes whose 30-second rolling-average heart rate sat more than 5 bpm above the cap, with the cap it was measured against ([StreamAnalysis::easyCapOverage()](app/Services/Run/Ingest/StreamAnalysis.php#L524), [StreamSummary::overEasyCapSec()](app/Services/Run/Metrics/StreamSummary.php#L108)). Grading and the adapter read the figure and never re-read streams.
4. **Judging an easy effort.** A day is too hard when its time over the cap exceeds 15 minutes, or 20% of moving time when its runs total under 75 minutes ([EasyEffort](app/Services/Run/Plan/EasyEffort.php#L18)). This applies to Easy days and Long days with no marathon-pace block. For a capped athlete the heart-rate rule decides on its own ([SessionIntentJudge::steady()](app/Services/Run/Plan/SessionIntentJudge.php#L190)). On a marathon-pace long run the block keeps its pace verdict, and the easy running around it is held to the same rule after the block's own minutes are taken off the time over the cap ([SessionIntentJudge::withEasyParts()](app/Services/Run/Plan/SessionIntentJudge.php#L240)). A run with no heart rate falls back to the pace-first rule; an athlete on config-default zones keeps pace first, with the stream rule replacing the old zone-share rescue.
5. **A ragged day.** The same rule defines a ragged day for the adaptive plan, now on Easy days and on Long days with no marathon-pace block, and a single day past 30 minutes, or 40% of a run under 75 minutes, is egregious ([PlanAdapter::previousWeekExecution()](app/Services/Run/Plan/PlanAdapter.php#L273), [EasyEffort::egregious()](app/Services/Run/Plan/EasyEffort.php#L66)). The ragged-day count is unchanged. `EASY_DAY_HARD_SHARE` (20%) and `EGREGIOUS_EASY_DAY_HARD_SHARE` (40%) are gone from grading and the adapter.
6. **Readiness on quality, today's row only.** At the mildest `ModerateOk` triggers, mild fatigue or soreness or fair or poor sleep alongside supporting load with no stronger trigger beside them ([ReadinessClamp::isMildModerate()](app/Services/Run/Plan/ReadinessClamp.php#L53)), a Tempo or Interval keeps its minutes and its pace slows by 3% ([ReadinessClamp.php:99](app/Services/Run/Plan/ReadinessClamp.php#L99)); the slowed pace is what the athlete is shown, so grading judges against it. A load trigger that caps the day by itself (a demanding session in the last 24 hours, closely spaced hard sessions, running ahead of plan, load above the personal range) or moderate fatigue keeps the 0.75× cut or easy. A time trial at `ModerateOk` is eased to an easy run of its distance and so counts as skipped and gets its one retry ([ReadinessClamp.php:92](app/Services/Run/Plan/ReadinessClamp.php#L92)). Goal-pace work at a mild trigger keeps its goal pace and takes the 0.75× minutes cut. `EasyOnly` and `Rest` are unchanged.

## Research basis

No peer-reviewed study defines how much time above the first threshold an easy session may hold.

- **15 minutes, a published convention.** Sylta, Tønnessen & Seiler 2014, IJSPP 9:100–107, [doi:10.1123/ijspp.2013-0298](https://doi.org/10.1123/ijspp.2013-0298): of 570 elite sessions, a continuous session with more than 15 minutes in zone 2/3 was classed as zone 2/3. A method choice, not a tested threshold. **Convention.**
- **Session goal against time in zone.** Seiler & Kjerland 2006, Scand J Med Sci Sports, [doi:10.1111/j.1600-0838.2004.00418.x](https://doi.org/10.1111/j.1600-0838.2004.00418.x). It gives no per-session limit. **Measured.**
- **Skipping the first 5 minutes.** HR on-kinetics τ ≈ 58 s, about 95% of the steady value in roughly 3 minutes (Hunt, Fankhauser & Saengsuwan 2015, BioMed Eng OnLine 14:117, [doi:10.1186/s12938-015-0112-7](https://doi.org/10.1186/s12938-015-0112-7)). Wrist sensors are worst at motion onset (Van Oost et al. 2025, Sensors 25:6319, [doi:10.3390/s25206319](https://doi.org/10.3390/s25206319); walking only). **Measured basis, heuristic size.**
- **The 5 bpm band and 30-second averaging.** Wrist HR mean bias is −0.51 bpm (Zhang et al. 2020 meta-analysis, J Sports Sci 38:2021–2034, [doi:10.1080/02640414.2020.1767348](https://doi.org/10.1080/02640414.2020.1767348)), but sample-level limits run about ±27 bpm (Wang et al. 2017, JAMA Cardiol 2:104–106, [doi:10.1001/jamacardio.2016.3340](https://doi.org/10.1001/jamacardio.2016.3340)). Error grows with speed (Pasadyn et al. 2019, [doi:10.21037/cdt.2019.06.05](https://doi.org/10.21037/cdt.2019.06.05); Gillinov et al. 2017, MSSE, [doi:10.1249/MSS.0000000000001284](https://doi.org/10.1249/MSS.0000000000001284)). **Measured basis, heuristic size.**
- **Drift.** HR rises about 9–11% over roughly an hour at moderate intensity (Coyle & González-Alonso 2001, Exerc Sport Sci Rev 29:88–92, [doi:10.1097/00003677-200104000-00009](https://doi.org/10.1097/00003677-200104000-00009)); about 2% at 22°C against 11% at 35°C (Lafrenz et al. 2008, MSSE 40:1065–1071, [doi:10.1249/MSS.0b013e3181666ed7](https://doi.org/10.1249/MSS.0b013e3181666ed7)), and 151 to 169 bpm at 35°C (Wingo et al. 2005, MSSE 37:248–255). Cycling studies under an hour; no verified running data exists for 2–3 hours. This is why the long-run hint says the pace may slow late. **Measured.**
- **The doubled egregious line and the 3% readiness nudge.** **Heuristics.**

## Consequences

- A capped athlete running easy at a quick pace under the cap reads `hit`; one jogging slowly above it for 20 minutes reads `too_hard`, where pace alone would have passed it.
- A long run held too hard can now count toward a ragged week; a marathon-pace long run cannot.
- A time trial on a mildly concerning day becomes an easy run and is offered again the following week; before, it ran as a 0.75× dose and used up the cycle.
- Old intent evidence carrying `zone` and `above_zone_pct` is still worded as before ([IntentOutcome](app/Services/Run/Plan/IntentOutcome.php)); a regrade replaces it.
- Rollout, each run by the owner on prod after deploy, in order: `run:backfill-easy-effort` writes the figure onto every past run from its stored streams under the athlete's current zones (`BackfillEasyEffortCommand` (removed 2026-10-08)), then `plan:regenerate`, then `plan:regrade-season`. Ingest and zone recalibration keep the figure current after that.

## See also

- [[plan-periodizer]], [[coaching-evidence]], [[stream-analysis]], [[readiness-clamp-is-advisory]], [[a-time-trial-every-six-weeks]], [[goal-pace-work-in-the-last-weeks]]
