# B2 — Compliance v2

**Wave** 2a · **Slot** worktree-be · **Blockers** `B3` · **Status** merged ([#661](https://github.com/nukipratama/temari/pull/661), squashed as `5ec8d3e6`)

## Goal

`PlannedSessionStatus` gains `Overreached` and `Skip`; a 0-100 per-day score, a week tally, and "ran
anyway"; `SessionMatcher` rewritten to **persist** (today it is km-only via
`DONE_FRACTION`/`PARTIAL_FRACTION` and computed at render); a backfill command; `PlanAdapter` reads
the new adherence.

## What actually landed

**A historical verdict freezes; a forward-looking target doesn't.** `B3` concluded segments should
stay render-time-only, since they describe days that haven't happened yet. A compliance grade is the
opposite: once a day is in the past and judged, that judgment should freeze rather than silently
reflow as the athlete's fitness baseline drifts weeks later. So `SessionMatcher` now has two halves —
[SessionMatcher::scoreFor()](../../app/Services/Run/Plan/SessionMatcher.php) is the pure grading
function, and a new daily command persists its verdict once, the day it becomes gradable.

**Continuous score, not a 3-bucket match.** `scoreFor()` computes
`score = round(completedKm / plannedKm * 100)`, uncapped upward, and derives the status from it:
`<35` `missed`, `35-84` `partial`, `85-129` `done`, `>=130` `overreached` (`OVERREACHED_FRACTION`).
`skipped` wins over everything regardless of km logged. A rest day always reads `done` with a `null`
score (nothing was prescribed to grade); whether an activity was logged on it anyway is a separate
`ran_anyway` boolean, not a status change — running on a rest day isn't a failure to grade, and
grading it `done` either way keeps rest days out of the missed-week count.

**Skip is a new pre-emptive excuse, not a new way to fail.** `PATCH /plan/sessions/{id}` with
`skipped: true` (a new "Skip"/"Unskip" button in `Plan.tsx`, alongside Block/Pin/Delete) marks a day
excused ahead of time. It's distinct from Block: Block rewrites what the day *is* (a rest day, still
graded); Skip leaves the prescribed session as-is but excuses the athlete from being graded on it once
the compliance pass reaches it. `PlannedSessionStatus::isCredited()`: `done`/`partial`/`overreached`
count; `planned`/`missed`/`skip` don't.

**Persisted once, via a daily pass — no separate backfill command.** New `plan:score-compliance`
(`ScoreComplianceCommand`, daily 00:03, before the existing Monday 00:07 `plan:regenerate`) finds
every still-`planned` row that's now past-due, across every user, and writes `status`,
`compliance_score`, `ran_anyway` back via a new `SessionMatcher::scoreRange()`. It's idempotent by
construction (a row is only ever selected while still `planned`) and doubles as the backfill
mechanism for any historical backlog — "any `planned` row that's now past, regardless of age" already
covers a cold start, so a dedicated `plan:backfill-compliance` command would have been pure
duplication. `PlanController`/`CurrentWeekPlanBuilder` now read the stored `status` column as the
primary source of truth; the old render-time `SessionMatcher::statuses()` is demoted to a defensive
fallback for the rare row still `planned` despite being past-dated (the daily command hasn't reached
it yet — normally an empty set).

**Adherence reads persisted scores, capped per day.** `PlanAdapter::previousWeekAdherencePct()`
replaces the old completed-share-of-elapsed-sessions computation: it averages last week's persisted
`compliance_score`, capping each day at `min(100, score)` first so one `overreached` day can't mask
other missed ones. Rest (`compliance_score IS NULL`), still-unscored (`planned`), and `skip` days are
excluded entirely — rest asks for nothing, an unscored row has no verdict yet, and skip is excused by
definition. No scoreable days at all (first week ever, an all-rest week) reads as perfect adherence,
matching the old method's own empty-week default. `PlanAdapter::decide()`/`forWeek()`/`reasonFor()`
moved from a `float` 0.0-1.0 fraction to an `int` 0-100 percent throughout.

**A genuine 3rd-duplication DRY extraction, not a speculative one.** `ScoreComplianceCommand` needed
the exact same "which day is this week's primary Easy, what's each day's core km" computation
`PlanController` and `CurrentWeekPlanBuilder` already had inline (and had to keep agreeing on, per
`B3`'s own coupling note). Pulled into
[PlanRenderer::primaryEasyDate()/plannedKmByDate()](../../app/Services/Run/Plan/PlanRenderer.php), so
all three callers share one computation rather than a third hand-copy silently drifting.

**A real circular-test-data bug found and fixed along the way, not a scoring bug.**
`CurrentWeekPlanBuilderTest`'s "credits a past day" test originally logged an activity at exactly its
own just-computed target, which (for a fresh user with no prior history) retroactively became the new
"longest run in the trailing 28 days" and shrank the very target it was being judged against —
structurally, logging exactly the pre-log target this way always lands around 154% (`Overreached`),
for any value. Fixed by seeding a separate, stable anchor activity first so the test's own logged run
can't influence its own baseline. The same fix shape was needed in the new
`ScoreComplianceCommandTest`.

## Files touched

New: migration `2026_08_29_143930_add_compliance_columns_to_planned_sessions_table.php`,
`app/Console/Commands/Run/ScoreComplianceCommand.php` (+test).
Modified: `app/Enums/PlannedSessionStatus.php`, `app/Services/Run/Plan/SessionMatcher.php` (+test,
full rewrite), `app/Services/Run/Plan/PlanAdapter.php` (+test), `app/Services/Run/Plan/PlanRenderer.php`,
`app/Services/Run/Plan/Periodizer.php`, `app/Models/PlannedSession.php` (+test),
`app/Http/Controllers/PlanController.php` (+test), `app/Http/Requests/UpdatePlannedSessionRequest.php`
(+test), `app/Services/Run/Plan/CurrentWeekPlanBuilder.php` (+test),
`app/Console/Commands/GenerateTypeScriptEnumsCommand.php`, `database/factories/PlannedSessionFactory.php`,
`tests/Feature/Console/DemoBillingExclusionTest.php`, `tests/Feature/Plan/MissedWeekAdaptationTest.php`,
`resources/js/types/generated.ts` (regenerated), `resources/js/types/inertia.ts`,
`resources/js/pages/Plan.tsx` (+test), `resources/js/components/home/WeekPlanWidget.tsx` (+test),
`docs/features/plan-periodizer.md`.

## Blockers

`B3` — must run after `WeekPlanDay` is frozen. Landed after `B3` merged (`ab8f33aa`).

## Acceptance criteria

- [x] `PlannedSessionStatus` has `overreached` and `skip`; `isCredited()` covers all six cases.
- [x] A day's compliance is a continuous 0-100 score, not a 3-bucket match.
- [x] The verdict is persisted once, via a daily command, not recomputed live on every page load.
- [x] Skip is a distinct excused-ahead-of-time action, reachable from `Plan.tsx`, excluded from
      adherence.
- [x] `PlanAdapter`'s weekly adherence reads the persisted, capped scores.
- [x] No separate backfill command — the daily command's query already covers a cold start.

## Coverage delta

Backend: full suite 3649/3649 passing (up from 3636 pre-slice) — 6 new tests in
`ScoreComplianceCommandTest`, plus rewritten/expanded coverage in `SessionMatcherTest` (12),
`PlanAdapterTest` (17), `PlannedSessionTest` (9), `PlanControllerTest` (+1 skip test),
`UpdatePlannedSessionRequestTest` (+2). Frontend: global coverage stays above the 95%-lines/
95%-functions gate (95.43% statements / 89.07% branches / 95.06% functions / 95.80% lines) after
adding the skip toggle and its tests to `Plan.tsx` and `WeekPlanWidget.tsx`.

## Verification notes

`pest --group=structure --no-tia` (37/37), `bin phpstan analyse --debug` (0 errors — caught a
nullable-`round()` argument and a `User::query()->find()` type mismatch in the new command), `bin
pint` / `bin rector --dry-run` clean, full `bin pest --parallel --no-tia` (3649/3649 — including a
real regression caught and fixed in `MissedWeekAdaptationTest`, which called `Periodizer::regenerate()`
twice without ever running the daily compliance pass in between, so the persisted-adherence model
correctly read "nothing scored yet" instead of "missed"; fixed by running `plan:score-compliance`
between the two regenerate calls, matching production's real ordering), `npx tsc --noEmit` clean,
`npm run build && check:chunks` green, `npm run test:coverage` green, `check-raw-palette.mjs` /
`check-doc-citations.php` clean. Coupling: `resources/js/types/generated.ts` is also touched by `B4`
(`AnalysisType`) — see [../README.md](../README.md) §8; this slice's addition
(`PlannedSessionStatus`) is independent of `B4`'s, so a straightforward re-generate should resolve
any merge overlap.

## Open questions

None blocking. One thing intentionally deferred: per-segment/per-day compliance *override* (e.g. a
coach or the athlete manually re-grading a day) has no request/API surface — only the automated
`scoreFor()` path writes `compliance_score`. Not requested, no UI need identified yet.
