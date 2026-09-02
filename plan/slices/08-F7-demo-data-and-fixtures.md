# F7 — Demo data + shared fixtures

**Wave** 1 · **Slot** worktree · **Blockers** `B2`, `B3` · **Status** in-review

## Goal

`database/seeders/Demo/` learns the new backend shapes from `B2` (Compliance v2) and `B3` (session
segments) so screen slices are not designed against empty states (R5). A parallel visual-parity
audit (`V0`) confirmed three concrete gaps directly against the running app: zero `PlannedSession`
rows for the demo user (Home's `WeekPlanWidget` and Plan's populated state never render), zero
`InboxNotification` rows despite 113+ runs and an unlocked badge existing, and zero `trend_read`
`Analysis` rows anywhere in the database (Trends' "Temari's Read" card renders empty). `F7` closes
all three directly in `demo:seed`, plus backfills `plan_day_voice`/`plan_week_voice`/
`plan_season_voice` (B4's three narration types), which had the identical gap shape.

## What actually landed

**No `SegmentKey`-shaped column to seed.** `B3` landed the opposite of this slice's original
premise: segments are computed 100% render-time by `SegmentGenerator::generate()`, never
persisted (`plan/slices/09-B3-session-segments.md:28`). Seeding realistic `PlannedSession` rows is
therefore just seeding the flat columns (`phase`, `session_type`, `status`, `compliance_score`,
`skipped`, `ran_anyway`) — segments render correctly on their own once those are right.

**`PlannedSession` seeding reuses the real `Periodizer`/`WeekPlanBuilder`/`TrainingBaseline`
services rather than hand-rolling a second template.** `DemoRunSeeder::seedCurrentWeekPlan()` calls
`Periodizer::regenerate($user)` first — the actual production entry point, which writes a real
12-week horizon (`Periodizer::HORIZON_WEEKS`), phase-aware, race-aware, `TrainingPreference`-aware.
`Periodizer::regenerate()` only ever writes **today-forward** (`WeekPlanBuilder`'s own docblock:
"regeneration only ever writes today-forward, past days stay untouched") — a fresh demo account has
no prior rows, so the current week's past days (Monday..yesterday) get nothing from that call alone.
The seeder closes that gap by calling `WeekPlanBuilder::build()` a second time for the *same*
current week with `notBefore: null`, reading back the phase `regenerate()` just wrote so both calls
agree, and only using the *past*-day subset of that second result (days `regenerate()` already
covered are skipped). Past training days cycle through
`Overreached → Done → Partial → Skip` (scores 145/100/55/null, matching
`SessionMatcher`'s own `DONE_FRACTION`/`PARTIAL_FRACTION`/`OVERREACHED_FRACTION` bands) so Today's
day-glyph strip shows real variety instead of a stale all-`Planned` week; past rest days are always
`Done` with a null score, matching `SessionMatcher::scoreFor()`'s own rule. On a week where "today"
is itself the week's Monday (no past days at all — verified live against the running app, where
this was in fact the case) the second call is a no-op, which is correct: there is nothing to
backfill.

**`plan_day_voice`/`plan_week_voice`/`plan_season_voice` had a ready-made demo entry point that was
simply never called.** `PlanNarrationRequester::ensureDemoFilled()` (`B4`) is documented as "the
demo account's equivalent of `requestForCurrentWeek()`... the same path the demo account's manual
'Reread' already resolves through" — it already existed, rule-based only, `refillDone: false` so a
re-seed doesn't rewrite already-filled rows. `seedCurrentWeekPlan()` calls it once, right after the
`PlannedSession`/`PlanAdaptation`/`Season` rows it depends on exist. No new narration plumbing
needed — this was a pure "the seeder forgot to call the thing that already does this" gap.

**`trend_read` has no seeder-reachable production entry point** — `TrendReadCommand` explicitly
excludes demo users (`User::query()->notDemo()`), matching the demo billing exclusion, and there is
no `ensureDemoFilled()`-equivalent for it. `RuleBasedNarrationFiller` already had a `trendRead()`
arm (unused until now). Added `DemoRunSeeder::seedTrendRead()`, calling
`AnalysisService::requestRuleBased()` directly for each of `AnalysisType::TREND_READ_RANGES`
(`30d`/`90d`/`12mo`) — the same rule-based-fill primitive `ensureDemoFilled()` itself uses
underneath, just called one level lower since no per-surface wrapper exists yet for this type.

**The `InboxNotification` gap was a queue-processing gap, not a missing feature.**
`GrantEligibleUnlocksAction` already calls `$user->notify(new UnlockGrantedNotification($celebration))`
for every newly-granted unlock, and `UnlockGrantedNotification::via()` already resolves to
`InAppChannel` unconditionally. The notification is `ShouldQueue`, `QUEUE_CONNECTION=database` by
default, and nothing in `demo:seed` ever runs a queue worker — so the job sat in the `jobs` table
forever, and `InAppChannel::send()` (the only code that writes an `InboxNotification` row) never
ran. Fixed with `DemoRunSeeder::withSyncQueue()`: forces `queue.default` to `sync` only around the
`GrantEligibleUnlocksAction` call (both in `seed()` and `refreshToday()`), so the queued
notification executes inline instead. Confirmed safe for the demo account specifically:
`UnlockGrantedNotification::via()` returns `ChannelRouter::inAppOnly()` unconditionally (never the
outbound Telegram/web-push set), so this can never produce a real outbound side effect regardless
of demo status.

**Two more inbox rows are seeded directly, bypassing the queue entirely**, so the Inbox page has a
believable populated state (today's post-run summary, the last closed week's recap) even on a
re-seed where no *new* unlock fires (the common case — after the first seed, nothing new unlocks).
`DemoRunSeeder::seedNarrationInboxEntries()` builds each row from `AnalysisReadyNotification::toInbox()`
— a plain, non-queued method — called directly on the already-`Done` `post_run_speech`/`weekly_recap`
`Analysis` rows, then persists via `InboxNotification::record()` (the exact production write path,
skipping only the `via()`/channel-routing layer, which the demo would resolve to in-app-only anyway).
The weekly recap row's `created_at` is explicitly backdated to the week's own end (a raw query
builder `update()`, since `created_at`/`updated_at` aren't fillable) so it lands in the Inbox's
"this week" bucket rather than "today", matching when a real recap would have landed.

**A real, reproducible flake was caught and fixed during implementation, not shipped.** The first
version of the "today's post-run summary" query used an unordered `Activity::query()->...->first()`
to find "today's" activity. Live data confirms this dataset regularly has 2-3 activities on the same
calendar date (a filler blueprint can coincidentally land on the same day as the always-seeded D-0
keep-alive run) — an unordered `first()` is not guaranteed stable across executions on such a set,
and one `composer check` run (parallel, TIA on) surfaced it directly: a second `demo:seed` call
picked a *different* "today" activity than the first, producing a 3rd `InboxNotification` row where
the idempotency assertion expected 2. Fixed with an explicit `->latest('id')`, which deterministically
targets the D-0 keep-alive run (always seeded last). Re-run 3x isolated and 2x under the full
`composer check` gate after the fix with no repeat.

**The Trends fitness/fatigue chart gap is a genuine, self-resolving data gap, not a component
bug** — investigated and confirmed, not assumed. `TrendsController` and the frontend
(`FitnessTrend.tsx`/`LoadTrend.tsx`) match field-for-field with no shape mismatch. The chart is fed
live by `TrainingLoad::ctlTrend()`/`strainMonotonyTrend()`, which query `ActivityDetail` on a
strict **rolling 365-day window ending at real "now"** — architecturally decoupled from
`WeeklySnapshot` (a separate, persisted computation anchored to each week's own historical
`week_ending`, which is why 29 `WeeklySnapshot` rows could exist with real CTL/ATL data while the
live chart plotted nothing: the underlying `ActivityDetail` dates had aged out of the 365-day
window relative to real "now" at the time V0's audit ran). Verified directly: after a fresh
`demo:seed` (which re-anchors every blueprint's date to `Carbon::today()->subDays(N)`),
`TrainingLoad::ctlTrend($user, 365)` returns 183 non-empty points. No frontend or controller change
was needed or made — the fix is that `demo:seed` (and the existing `demo:daily-refresh` scheduler)
keeps the dataset's dates fresh, which they now do by design; this was not true of `PlannedSession`/
`InboxNotification`/`trend_read`, which this slice actually seeds.

**The shared `resources/js/test/fixtures/` module is deliberately deferred, not built.** The
original stub's second goal (one canonical `WeekPlanDay` literal etc. for the twelve wave-2b screen
slices to share) is lower priority than the three data gaps above, and all twelve wave-2b screen
slices (`S1`-`S12`) already merged before this slice reached the front of the worktree queue — each
hand-rolled its own local fixtures rather than waiting on a shared module that didn't exist yet, so
there is no live consumer for it today. Building one now would be speculative (no requester,
CLAUDE.md §2 "no abstractions for single-use code" / "no speculative... configurability"). Left as
an explicit follow-up for whichever slice next needs to share a `WeekPlanDay`-shaped fixture across
files, rather than silently dropped.

## Files touched

`database/seeders/Demo/DemoRunSeeder.php` (extended: `seedCurrentWeekPlan()`, `seedTrendRead()`,
`seedNarrationInboxEntries()`, `recordInboxFromAnalysis()`, `withSyncQueue()`, new constructor deps
`Periodizer`/`WeekPlanBuilder`/`TrainingBaseline`/`PlanNarrationRequester`),
`tests/Unit/Console/Commands/DemoSeedCommandTest.php` (extended, no new test — existing "seeds a
complete, login-ready demo dataset and stays idempotent across re-runs" test grew assertions for
all three gaps plus their idempotency across a second re-run).

Docs kept fresh in the same PR (both had gone stale — a pre-existing line-drift the doc-citation
guard caught, and an ADR consequence this slice's own fix made false):
`docs/architecture/ai-narration-internals.md` (corrected `DemoRunSeeder.php` line citations that
drifted past the guard's tolerance, noted the new `trend_read`/`plan_*_voice` fill path),
`docs/decisions/demo-notifications-are-inbox-only.md` (dated correction: its "21 rows" unlock claim
was already false — 0, due to the queue-processing gap this slice fixes — and its "not populated
with post-run or recap rows... has not been made" line is exactly the gap `seedNarrationInboxEntries()`
now closes; decision + reasoning left untouched per ADR immutability, only the consequences section
corrected).

## Blockers

`B2`, `B3` — both merged well before this slice started (`B3` #660, `B2` #661), matching the
"runs after wave 2a's first two backend slices land" note in the original stub. `B4` (plan
narration) also merged (#663) and turned out to be a soft dependency too: `PlanNarrationRequester::ensureDemoFilled()`
is what `plan_day_voice`/`plan_week_voice`/`plan_season_voice` seeding reuses directly.

## Acceptance criteria

- [x] `demo:seed` produces nonzero `PlannedSession` rows for the demo user: a full 12-week horizon
      (`7 * Periodizer::HORIZON_WEEKS` = 84 rows), current-week past days scored/skipped rather
      than left `Planned`, current-week future days left `Planned` (matching real production
      behavior — `Periodizer::regenerate()` never marks a future day anything else).
- [x] `demo:seed` produces nonzero `InboxNotification` rows for the demo user: the real
      `GrantEligibleUnlocksAction` → `UnlockGrantedNotification` → `InAppChannel` pipeline now
      actually executes (was silently queued and never processed), plus a directly-seeded
      post-run summary ("today" bucket) and weekly recap (backdated into "this week" bucket).
- [x] `demo:seed` produces nonzero `trend_read` `Analysis` rows (all three `TREND_READ_RANGES`),
      and (checked while in there, per the brief) nonzero `plan_day_voice` (7, current week),
      `plan_week_voice` (1), `plan_season_voice` (1) rows — all rule-based, zero LLM tokens.
- [x] Investigated whether the Trends fitness/fatigue chart's empty state was a real
      frontend/controller bug or a data gap: confirmed genuine data gap (live 365-day rolling
      window vs. persisted `WeeklySnapshot`), not a bug — no frontend/controller change made or
      needed; documented in "What actually landed" above with the verification method.
- [x] `demo:seed` stays idempotent: a second run under the same clock duplicates nothing and
      converges to the same row counts for all three new surfaces (`DemoSeedCommandTest`, extended).
- [x] No LLM tokens spent — every new narration surface goes through
      `RuleBasedNarrationFiller`/`requestRuleBased()`/`ensureDemoFilled()`, matching the existing
      `AnalysisService::withoutDispatching()` pattern the whole seeder already runs inside.
- [x] A real flake (unordered `first()` query, confirmed via live data showing 2-3 activities per
      calendar date) was caught via a full `composer check` run and fixed with an explicit
      `->latest('id')`, not shipped or worked around.
- [ ] Shared `resources/js/test/fixtures/` module — deliberately deferred (see "What actually
      landed"), not built in this slice.

## Coverage delta

Backend: full suite 3690/3690 passing (no count change — this slice extends the existing
`DemoSeedCommandTest` test rather than adding a new one). Frontend: n/a — no frontend files
touched (`npm run test`: 218/218 files, 2057/2057 tests passing, confirming no regression from the
backend-only change; `tsc` clean).

## Verification notes

`pest --group=structure --no-tia` (38/38), `bin phpstan analyse --debug` (0 errors), `bin pint
--test` (clean), `bin rector --dry-run` (0 changes), full `bin pest --parallel --no-tia` (3690/3690,
run twice for flake confidence after the `->latest('id')` fix), `npm run typecheck` (clean), `npm
run test` (2057/2057), full `composer check` (green after the flake fix — the pre-fix run is what
surfaced the flake in the first place).

Live verification against the running app (not just tests), per the task's explicit ask, since
`migrate:fresh` is hard-blocked by the local guard hook against destructive DB operations even in a
worktree — verified instead by re-running `demo:seed` against the existing seeded dataset (the
seeder is idempotent either way) and by temporarily mocking a non-Monday "today" via
`Carbon::setTestNow()` in `tinker` to directly exercise and confirm the past-day variety path (the
real "today" at verification time was itself a Monday, so the live DB alone never exercised that
branch) before resetting the clock and re-seeding for real. Exact before/after counts, all for the
demo user specifically:

| surface | before | after |
|---|---|---|
| `PlannedSession` | 0 | 84 (12-week horizon; past days of the current week scored/skipped, e.g. `overreached`/`done`/`partial`/`skip` when mocked mid-week) |
| `InboxNotification` | 0 | 2 (`post_run` + `weekly_recap`; badge-unlock rows join the same real pipeline whenever a *new* unlock fires, e.g. on a genuinely fresh account) |
| `trend_read` `Analysis` (whole DB) | 0 | 3 (`30d`/`90d`/`12mo`, all `done`) |
| `plan_day_voice` / `plan_week_voice` / `plan_season_voice` | 0 / 0 / 0 | 7 / 1 / 1 (all `done`) |

The Trends chart question resolved to: **data gap, not a component bug.**
`TrainingLoad::ctlTrend($user, 365)` returned 183 non-empty points against the freshly-seeded
dataset (verified via `tinker`) — the controller/frontend shapes already matched; the chart empties
only when the underlying `ActivityDetail` dates have aged past the live 365-day window, which a
fresh `demo:seed` (and the existing daily refresh scheduler) resolves on its own.

## Open questions

None blocking. The shared `resources/js/test/fixtures/` module (original stub's second goal) is a
deliberate, explicit follow-up — see "What actually landed" for why it was deferred rather than
built speculatively against zero current consumers (all twelve wave-2b screen slices already
shipped their own local fixtures). Whoever next needs to share a `WeekPlanDay`-shaped literal
across test files is the natural trigger to finally build it.
