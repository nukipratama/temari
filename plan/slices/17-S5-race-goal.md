# S5 — RaceGoal

**Wave** 2b · **Slot** parallel worktree · **Blockers** `F4`, `B1`, `F6` · **Status** in-review ([#670](https://github.com/nukipratama/temari/pull/670))

## Goal

Port `pages/Race.tsx` (416 L) + `components/race/`, including `CtlTrendChart.tsx` which `F6` already
themed for both grounds — this slice consumes it, does not redesign it.

## What actually landed

**The prototype's `aiReplanState`/`onTriggerAiReplan` on `RaceGoalScreen` is not a Race-specific
spec.** Reading the prototype's own dev shell (`resources/brand/prototype/src/App.tsx`) shows it is
a single `useState` toggle reused verbatim as a prop across `PlanScreen`, `RaceGoalScreen` and
`SettingsScreen` — a shared demo-story control for "AI is doing something," not a designed
Race-triggers-a-plan-regenerate flow. The shipped app already has a real, tested version of that
concept on the Plan page (`PlanController::regenerate()` + `PlanNarrationRequester`'s cooldown, from
`B4`), with its own real LLM cost. Inventing a second, Race-specific regenerate-with-cooldown
endpoint would be new backend behavior this slice was not chartered to add (decision 5's default
posture is restyle existing functionality, not invent new backend shape), so the submit button keeps
its real, unchanged `POST /race` save flow — just restyled onto the prototype's button look. Recorded
here as a routine implementation call, not escalated, since the prototype's own source settles it.

**`ProjectionGauge`** (`resources/js/components/race/ProjectionGauge.tsx`) is new: a semi-circle SVG
gauge placing the predicted finish time within its low-high range, ported from the prototype's own
gauge shape (arc + animated fill + marker dot). Pure client-side derivation from the `projection` prop
the backend already returns (`low_sec`/`predicted_sec`/`high_sec`) — no new backend/API shape, matching
the "reasonable client-side addition over data that already exists" carve-out. Uses the page's existing
`useCountUp` tier-2 animation convention.

**Two new client-side-only goal-time warnings**, ported from the prototype's `RaceGoalForm` sanity
checks, added to `resources/js/lib/raceGoal.ts` alongside the existing `goalTimeError`:
`impossiblePaceWarning` (pace quicker than a world-record floor, `IMPOSSIBLE_PACE_SEC_PER_KM = 155`)
and `ambitiousGoalWarning` (goal well ahead of the athlete's own projected range,
`PERSONALIZED_STRETCH_RATIO = 0.9`). Both are advisory only (don't block submit, unlike
`goalTimeError`). `ambitiousGoalWarning` needs a distance to compare the form against, but
`ProjectionPayload` carries no distance field — the projection is always computed server-side from
the *saved* race's own `distance_m` (`RaceController::index()`), never the form's live distance
state, so that's the one distance a client-side check can validly compare against. No backend change
needed: the existing `race` prop already carries `distance_m`.

**The prototype's warning colors don't survive the token mapping and were substituted.** Its
`RaceGoalForm` uses a raw `#d97706` hex for the pace warning and `citrus`/`citrus-ink` for the
ambition warning — neither has a home in the app's palette (`citrus` is reserved for PR/legendary
celebrations only per the `temari` skill, and raw hex fails `check:palette`). Both warnings collapse
onto `ember`/`ember-ink`, the page's own pre-existing token for `goalTimeError`, and reuse the
already-registered `ember/0.08` panel alpha (the same one `ErrorBanner`/`FlashNotice` use) rather
than introducing a new alpha value — only the new `resources/js/pages/Race.tsx` call site needed
adding to that entry's `over` map in `resources/brand/grounds.json`.

**The race-summary card split into two**, matching the prototype's `RaceCard`/`ProjectionBlock`
structure instead of one `Card` with an internal `border-t` divider: a race-facts card (name, date,
days to go, distance/goal-time stat tiles) and a separate projection card (gauge, predicted-time
headline, confidence caption). The standalone "low – high" range headline that used to sit above the
confidence caption was dropped in favor of the gauge's own low/high tick labels, which already carry
the same two numbers — a restyle of *how* the range is presented, not a cut of the information
itself (both `lowSecCount`/`highSecCount` count-up hooks were removed as now-unused).

**The page header adopts the two-line emphasized-headline pattern** already established by `Plan`
(`S4`) and `Trends` (`S6`) — `<em className="italic text-icon-accent">` on the second line — matching
the prototype's own copy split ("Your race, / *on the calendar.*" / "Give the plan / *something to
aim at.*"). The supporting paragraph underneath was already an exact Title-Case match for the
prototype's own copy and needed no change.

**`CtlTrendChart` and `PlanRaceTabs` are untouched.** The chart is `F6`'s (per this slice's charter);
`PlanRaceTabs` already renders the app's `SectionTabs` primitive with the same segmented-pill visual
language as the prototype's `ScheduleRaceTabs`, so there was nothing to restyle there.

## Files touched

New: `resources/js/components/race/ProjectionGauge.tsx` (+test).
Modified: `resources/js/pages/Race.tsx` (+test), `resources/js/lib/raceGoal.ts` (+test),
`resources/brand/grounds.json` (new `resources/js/pages/Race.tsx` call site registered under the
existing `ember/0.08` panel entry).

## Blockers

`F4`, `B1`, `F6`. All merged.

## Acceptance criteria

- [x] `Race.tsx` + `components/race/` restyled onto the prototype's visual/structural language
      (two-line hero headline, split race/projection cards, gauge) while preserving every existing
      real behavior: race save/update, the Riegel-projection display, and the fitness-trend chart.
- [x] `CtlTrendChart` consumed as-is, not redesigned (`F6` owns its appearance).
      `PlanRaceTabs` also left untouched — already on-brand.
- [x] Mock-only affordances in the prototype's demo shell for this screen do not become new
      race-specific backend behavior; the "AI replan" pill is confirmed via the prototype's own
      `App.tsx` to be a shared demo-shell mock, not a Race-specific spec — the real `POST /race`
      save flow is preserved unchanged.
- [x] New client-side additions (`ProjectionGauge`, the two goal-time warnings) derive entirely from
      data the backend already returns; no new backend/API shape introduced.
- [x] No raw palette colors or reserved tokens (`citrus`) introduced; both new warnings use the
      page's existing `ember`/`ember-ink` family, and `check:palette` / `DesignTokenContrastTest`
      (grounds.json registration) both pass.
- [x] UI chrome stays Title Case; no em-dashes in any new copy.
- [x] 1:1 test convention: `ProjectionGauge` has a co-located test; `raceGoal.ts` and `Race.tsx`
      tests extended to cover the two new warning paths (including the "distance no longer matches"
      guard); no new `EXEMPT`/`TS_EXEMPT` entries.

## Coverage delta

Backend: unaffected (no PHP touched). Full suite still 3737/3737 passing, 11418 assertions
(`bin pest --parallel --no-tia`).

Frontend: 219 test files / 2128 tests passing (up from 218/2115 pre-slice by the one new co-located
test file this slice adds, `ProjectionGauge.test.tsx`, plus new cases in `Race.test.tsx` and
`raceGoal.test.ts`). Coverage: **95.65% statements / 89.39% branches / 95.48% functions / 96% lines**
— a clean run with zero failures. A pre-slice baseline run under the same conditions could not be
captured cleanly (see Verification notes); the last recorded baseline in this program (`S6`, the most
recently merged wave-2b sibling) was 95.6% statements, so this slice's 95.65% is consistent with a
small net-positive delta from the newly-added, fully-tested `ProjectionGauge` and warning logic.

## Verification notes

`pest --group=structure` (38/38 — first run caught two unregistered-panel failures from the new
`bg-ember/8` call site, fixed by adding `resources/js/pages/Race.tsx` to the existing `ember/0.08`
entry in `grounds.json` rather than registering a brand-new alpha), full `bin pest --parallel --no-tia`
(3737/3737, 11418 assertions — unaffected since no PHP was touched), `npx tsc --noEmit` clean,
`npm run build && npm run check:chunks` green (Race is not one of the four hardcoded-budget routes),
`npm run test:coverage` clean on the run reported above, `check:palette` clean (461 files scanned,
zero off-token utilities), `php scripts/check-doc-citations.php` run directly per the ladder's rule
for any grounds-touching slice — clean.

**A resource-contention note, matching `S11`'s precedent**: this worktree shares the host with two
sibling wave-2b worktree stacks (`S2`, `S10`) running in parallel. Three consecutive
`npm run test:coverage` attempts each turned up exactly one or two failing test files, in a
*different*, always-unrelated file each time (`AppShell.test.tsx`, `CardReveal.test.tsx`, a run-card
snapshot test) — none in `Race.tsx`, `raceGoal.ts`, or `ProjectionGauge.tsx`. Verified this was
transient contention, not a real regression, by running the coverage suite against the pre-slice
baseline (via a temporary `git stash -u`) and reproducing the same pattern of unrelated one-off
failures there too. The coverage numbers recorded above come from the one fully clean run (zero
failures, 219/219 files) obtained across those attempts.

## Open questions

None blocking. One thing worth a human product call if it ever comes up: the prototype's shared "AI
replan" mock hints at a possible future product idea — should saving/updating a race goal also kick
off a real plan regenerate (mirroring Plan's own Regenerate-with-cooldown), rather than requiring the
athlete to separately hit Regenerate on the Plan page? This slice deliberately did not implement that
(see "What actually landed") since it would be new backend behavior with real LLM cost implications,
not a restyle — a genuine scope decision for whoever owns the product roadmap, not an implementation
detail this slice could resolve on its own.
