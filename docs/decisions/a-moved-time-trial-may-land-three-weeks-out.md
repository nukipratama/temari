---
title: A moved time trial may land three weeks before race week
description: A time trial that moves or is retried may fall three weeks before race week; only the last two weeks before race week stay trial-free, so a due trial the fatigue rule leaves without a slot is postponed rather than lost.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-11-02
code_refs:
  - app/Services/Run/Plan/TimeTrialSchedule.php
  - app/Services/Run/Plan/PlanAdapter.php
  - tests/Feature/Plan/TimeTrialPlanTest.php
---

# A moved time trial may land three weeks before race week

**Status:** Accepted (2026-11-02). Decision 1 of #2351. Supersedes the 3-week exclusion in rule 2 of [[a-time-trial-every-six-weeks]]; the rest of that decision stands.

## Context

A race season's last trial falls 4 weeks before race week (`TimeTrialSchedule::LAST_TRIAL_WEEKS_BEFORE_RACE`). When its week cannot hold it, rule 5 of [[a-time-trial-every-six-weeks]] moves it to the next week of its cycle, and rule 10 offers a skipped trial once more the week after. Both target the week 3 weeks out, which the old `TRIAL_FREE_WEEKS_BEFORE_RACE = 3` refused. The move and the retry therefore never worked for the cycle that matters most, the one closest to the race.

Here is how it showed up. On a 4-run week, Peak has one quality slot. A recovery week run over the easy heart-rate cap makes [PlanAdapter](app/Services/Run/Plan/PlanAdapter.php) return `RanTooHard` with `quality_delta: -1`, which removes that slot. The trial due that week had nowhere to go and was lost, leaving the athlete with no trial before the race.

## Decision

`TimeTrialSchedule::TRIAL_FREE_WEEKS_BEFORE_RACE` is 2. A trial is never offered within 2 weeks of race week. A trial that moves, or a retry, may fall 3 weeks before race week ([TimeTrialSchedule::allows()](app/Services/Run/Plan/TimeTrialSchedule.php)). Scheduled trials do not change: they are still due 4 or more weeks out, so only rules 5 and 10 can use the third week.

The fatigue rule is unchanged. A `RanTooHard` week still removes a quality session, and the trial is postponed a week rather than run tired.

The trial-free weeks still cover every taper. [PhaseSchedule::taperWeeksForDistance()](app/Services/Run/Plan/PhaseSchedule.php) gives a 2-week taper up to the marathon class, which ends in race week. The marathon class's 3-week taper starts 2 weeks before race week.

**Evidence: coaching practice, no trial behind the exact week.**
- Norris places a 5K tune-up 2 to 5 weeks before a 10K ([How to include tune-up races](https://lauranorrisrunning.com/tune-up-races/)).
- Pfitzinger & Latter's 8K–10K schedules carry two tune-up races, which secondary summaries put about 4 and 2 weeks before the goal race (*Faster Road Racing*, Human Kinetics 2015, ch. 10; the tables themselves were not checked).
- A taper guide comparing Pfitzinger and Daniels lets a 5K or 10K 2 to 3 weeks before the goal race replace that week's quality session, never in race week ([magic.training](https://magic.training/en/blog/marathon-taper-guide)).

A trial 3 weeks out sits inside all three. **Heuristic**: the window stays a named constant.

## Consequences

- A trial that a ran-too-hard week, a deload or the hard-day spacing pushes off its due week now runs 3 weeks before race week instead of disappearing.
- A trial in the third week out takes that week's goal-pace session, as rule 5 of [[a-time-trial-every-six-weeks]] already says for goal-pace weeks.
- Rollout: the weekly `plan:regenerate` picks it up; past days are untouched.

## See also

- [[a-time-trial-every-six-weeks]], [[plan-periodizer]], [[coaching-evidence]]
