# V0 — Visual parity audit

**Wave** 3 (blocking, inserted before `W1`) · **Slot** main checkout · **Blockers** none (all of wave 2b merged)

## Goal

Reconcile the shipped wave-2b screens (`S1`-`S12`) against the frozen prototype's own visual
spec, now that decision 5 is amended: the prototype is source of truth for UI/UX, not just a
loose reference a codemod sweep + independent redesign was allowed to drift from. For every
divergence found: if it traces to an already-logged decision or `ledger.md` verdict, it stays
as-is (documented, not re-litigated); if it's unintentional drift, it gets fixed to match the
prototype; if it's a genuine new conflict between "match the prototype" and a prior grilling
decision, it goes back to the user before anything is resolved.

The prototype ships its own review harness for exactly this comparison: `Rack`
(`resources/brand/prototype/src/components/rack/Rack.tsx`) renders every screen in three
side-by-side `PhoneFrame`s (light/dark/system) at a switchable viewport
(`components/rack/viewports.ts`), reachable by clicking through the `PAGES` nav in
`src/App.tsx`. No screenshot rig needs to be invented — it needs to be driven.

## What actually landed

**The comparison sweep itself**: all 12 prototype screens (via the prototype's own `Rack` harness,
mobile viewport, all three theme frames) captured against the shipped app's 11 matching pages
(both grounds forced via the `temari-theme` localStorage key), then reviewed in 4 parallel
design-QA passes. Every finding was cross-checked against `ledger.md` and the actual slice docs
before being treated as real — several apparent "big divergences" (Profile's persona chart, Trends'
badge board, Today's Kartu panel, History's cut filters) turned out to already match explicit
ledger verdicts or the streak-redesign amendment, not drift.

**A root cause bigger than styling, found mid-audit: the demo account had zero `PlannedSession`,
zero `InboxNotification`, and zero `trend_read` `Analysis` rows.** This was distorting the audit
itself — Today's `WeekPlanWidget` and Plan's populated state are conditional on data that never
existed for the demo account, so what looked like structural drift on Today/Plan/Inbox/Trends was
partly just "nothing to show." Resolved by `F7` (see
[08-F7-demo-data-and-fixtures.md](08-F7-demo-data-and-fixtures.md)), dispatched as a companion
piece to this slice rather than folded into it.

**Five genuine forks surfaced, all put to the user, all recorded in
[plan/README.md](../README.md) §5** (not re-litigated here): headline voice styling app-wide
(fork 1), header brand mark switching to the prototype's abstract ring (fork 2), Plan's phase-bar
and week timeline getting a real backend follow-up (fork 3), Today's supporting-detail disclosure
confirmed staying open by default as shipped (fork 4), and desktop `TopNav` getting a redesign
despite the prototype having no desktop nav spec at all (fork 5). Implementation of forks 1/2/3/5
is tracked as follow-up work, not part of this slice's own diff.

**First bug-fix batch** (dark-mode contrast + History truncation, confirmed via the sweep):
[Profile.tsx](../../resources/js/pages/Profile.tsx),
[variants.ts](../../resources/js/lib/variants.ts),
[pace.ts](../../resources/js/lib/pace.ts),
[RunListRow.tsx](../../resources/js/components/run/RunListRow.tsx).

### Fork 3 — Plan's phase bar and week timeline

`S4` had explicitly deferred the prototype's season-wide phase-progress bar and week-by-week
volume chart as a genuine data-availability fork (`PlanController::index()` only serves a rolling
3-history/4-lookahead window, not the full season). Closed by adding a dedicated, season-wide read
model rather than stretching the existing rolling-window payload to cover it.

**Backend**: [SeasonSummaryBuilder](../../app/Services/Run/Plan/SeasonSummaryBuilder.php) (new
class, wired into `PlanController::index()` as a `seasonSummary` Inertia prop alongside the
existing `season`/`weeks` props, untouched). One entry per week from `Season::$starts_at` to
`$ends_at` — phase, `history`/`current`/`lookahead` type, `planned_km`, and `actual_km` (from
`WeeklySnapshot` once one exists for that week). `planned_km` reuses the same deterministic,
season-start-anchored computation (`PhaseSchedule`/`WeekPlanBuilder`/`SegmentGenerator`) that
`SeasonService::generateGoals()` already sizes `SeasonGoal` targets with, rather than reading
materialized `PlannedSession` rows — those only ever cover the periodizer's rolling 12-week
horizon and get deleted/recreated on every weekly regeneration, so they're the wrong source for a
stable, whole-season figure (the exact reasoning `S4` already applied to `SeasonGoal` targets).
See [docs/features/plan-periodizer.md](../../docs/features/plan-periodizer.md)'s new "Season-wide
summary" subsection for the full writeup, including the accepted trade-off (a week's `planned_km`
won't reflect a real-time adaptation like an in-week deload the way the day-by-day schedule below
it on the page does).

**A genuine design fork surfaced and resolved without escalating**: the prototype's phase bar
hardcodes 4 fixed Base/Build/Peak/Taper buckets, but `App\Enums\PlanPhase`'s own docblock confirms
`Peak`/`Taper` only ever exist in race-oriented mode and `Deload` only in self-scaled mode — a
self-scaled season (no active race) never has a Base/Peak/Taper week at all, only a repeating
Build/Deload cycle. Porting the prototype's fixed-4-bucket model as-is would have silently divided
by zero / rendered a vacuously "done" empty bucket for roughly half of users. Resolved by grouping
*consecutive* same-phase weeks into bar segments instead of a fixed 4-column layout: a
race-oriented season still renders exactly 4 segments in the expected order, and a self-scaled one
renders its real repeating cycle instead of a fabricated race-shaped arc. This is a mechanical
generalization of the same idea (real phase data, no invented buckets), not a product-level
ambiguity, so it didn't warrant stopping to ask.

**Frontend**: [SeasonPhaseBar](../../resources/js/components/plan/SeasonPhaseBar.tsx) (the
grouped-segment bar above, plus a compact legend and a "logged so far" line) and
[SeasonWeekTimeline](../../resources/js/components/plan/SeasonWeekTimeline.tsx) (a planned-vs-
actual bar per week across the whole arc, windowed to 2 weeks either side of the current week with
the rest collapsed behind a toggle reusing `S4`'s `Collapsible` port) render additively on
`Plan.tsx`, below the existing season-goals grid and above the untouched day-by-day week list — no
existing mascot/phase-caption/`SeasonTrack`/day-card markup moved, hidden, or restyled. Phase fill
colors are a new `PHASE_COLORS` export in
[chartTokens.ts](../../resources/js/lib/chartTokens.ts): `leaf`/`horizonDeep`/`ember`/`citrus`/
`stone` for Base/Build/Peak/Taper/Deload, validated distinct via the dataviz skill's
`validate_palette.js` (CVD ΔE well above the 15 floor on every co-occurring pair) and deliberately
kept off `PALETTE.overloaded`/`gassed`/`chill`, which already carry per-run Mood meaning elsewhere
on the app.

**Scope deliberately left out**, consistent with the fork brief's "don't over-invest in
speculative edge cases": the prototype's per-week `WeekVolumeChart` plots planned-vs-actual at
*day* granularity within one expanded week — `WeeklySnapshot` only ever aggregates at week
granularity, so that gap from `S4`'s own notes is still real and still out of scope; my week
timeline instead compares planned-vs-actual at *week* granularity across the whole season, which
the brief's own wording ("week-by-week ... volume per week") asked for and the data supports. The
prototype's `WeekCluster`/`SeasonWeekRow` whole-week disclosure (which would nest the existing
Pin/Skip/Block/Move/Delete day controls behind a click) was not adopted, for the same
interaction-model reason `S4` already gave for skipping it — `SeasonWeekTimeline` is a separate,
read-only summary section, not a wrapper around the existing day cards.

## Files touched

The bug-fix batch above. The rest of this slice's "touch" is the audit and the doc/tracker
amendments — the actual fork implementations (headline voice, brand mark, Plan phase bar, desktop
nav) land in their own follow-up slices, not counted here.

**Fork 3's own files**: new
[SeasonSummaryBuilder.php](../../app/Services/Run/Plan/SeasonSummaryBuilder.php) (+test),
[SeasonPhaseBar.tsx](../../resources/js/components/plan/SeasonPhaseBar.tsx) (+test),
[SeasonWeekTimeline.tsx](../../resources/js/components/plan/SeasonWeekTimeline.tsx) (+test).
Modified: [PlanController.php](../../app/Http/Controllers/PlanController.php) (`seasonSummary`
prop wiring), [PlanControllerTest.php](../../tests/Feature/Http/Controllers/PlanControllerTest.php),
[Plan.tsx](../../resources/js/pages/Plan.tsx) (additive section + `PHASE_LABEL`/`PHASE_TONE`
exported for reuse), [chartTokens.ts](../../resources/js/lib/chartTokens.ts) (+test, new
`PHASE_COLORS` export), [plan-periodizer.md](../../docs/features/plan-periodizer.md).

## Blockers

None — all of `S1`-`S12` merged. Blocks `W1` onward (see `plan/README.md` §2/§3).

## Acceptance criteria

- [ ] Every one of the 12 ported screens screenshotted from the prototype's own `Rack` harness
      (both grounds at minimum; system optional) and from the shipped app at matching viewports
      and grounds.
- [ ] Every material divergence classified: matches a logged decision/ledger verdict (kept,
      cited) / unintentional drift (fixed) / genuine new conflict (escalated to the user via
      `AskUserQuestion` before resolving).
- [ ] No prototype file edited (still frozen per decision 19).
- [ ] `browser-review` run against the shipped app **after** any fix and **after** `npm run build`
      to confirm the fix landed and introduced no new overflow/regression.
- [ ] `plan/README.md` §5 amendment log and this slice doc's "What actually landed" filled in
      before `W1` unblocks.

## Coverage delta

n/a — both fixes below are markup/token/formatter corrections; existing 1:1 tests
(`RunListRow.test.tsx`, `Profile.test.tsx`, `Settings/Index.test.tsx`) already cover the touched
components' rendering and pass unchanged.

## Verification notes

Two confirmed, unrelated bugs found during the comparison sweep and fixed in one PR (both small,
both part of `V0`'s bug-fix scope):

**Dark-mode contrast.** Profile's "Training · pace targets" tiles (`StatTile tone="cream"`) and
the Settings "Send test notification" pill (`pillButtonVariants` `outline` tone) both painted with
the raw `cream`/`cream-deep` palette tokens (`--color-cream`, `--color-cream-deep`), which are
**not** redefined under `[data-theme='dark']` in `resources/css/app.css` — unlike `--color-card`/
`--color-border`, which are. That made both surfaces render light/white regardless of ground.
Fixed by switching both to the already-ground-reactive `card`/`border` pair: `StatTile`'s existing
`tone="card"` variant for the four pace tiles, and `bg-card border-border` in place of
`bg-cream border-cream-deep` for the `PillButton` `outline` tone (a shared component — the fix
applies everywhere `tone="outline"` is used, all of them equally miscoloured in dark mode before
this). `DesignTokenContrastTest` passed without needing a `grounds.json` regeneration — both
`card` and `border` were already classified panel backgrounds.

**History row title truncation.** `RunListRow.tsx`'s markup is structurally identical to the
prototype's `RunRow` (same `flex min-w-0` / `truncate` chain), so the truncation wasn't a CSS
layout bug — it was content width. The row calls `formatNaiveIdDate(detail.start_date_local)` for
the trailing date, and that formatter's `'short'` branch (the default) still sets
`weekday: 'long'` in its `toLocaleDateString` call, per its own doc comment ("weekday + date") —
correct for `formatNaiveIdDate`'s other ~15 call sites (chart labels, streak copy, PR captions),
but the S7 restyle put this same call inline, on one line, next to the title, where a full weekday
name ("Monday, Aug 31") eats most of the available width the shared component's flex-none siblings
would otherwise leave for the name — matching the prototype's own terse `"12 aug · 6:12am"` date,
not a weekday-prefixed one. Fixed by adding `formatNaiveMonthDayId` (`resources/js/lib/pace.ts`,
a null-safe wrapper around the existing `formatMonthDayId`/`parseNaiveLocalDate`, e.g. `"Aug 25"`)
and switching `RunListRow` to it, leaving `formatNaiveIdDate` and its other call sites untouched.

Both verified visually: `npm run build` + logging into the demo account at the iPhone 13 viewport,
`temari-theme` forced to dark for the contrast fix, both grounds for History.

## Open questions

None blocking. The four remaining forks (headline voice, brand mark, Plan phase bar, desktop nav)
are queued as follow-up slices — not open questions, since the user has already ruled on all of
them (see `plan/README.md` §5); they're implementation work, not undecided scope.
