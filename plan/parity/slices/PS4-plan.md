# PS4 — Plan

Plan to prototype parity, per `plan/parity/reference.md`'s Plan section. The largest of the eleven
screen slices: it builds P22's nested timeline, which no earlier slice attempted.

## Goal

Replace the shipped flat day-card list with `PlanScreen.tsx`'s nested structure — week-cluster
("N weeks behind/ahead") → week → planned-vs-actual daily bar chart + day rows → per-day expansion
carrying a zone-coloured session-segment bar, "Temari's take", a "view activity" link, and the Skip
and Move actions.

`S4` deliberately skipped this nesting to avoid hiding the day-action buttons. **P23 removed that
objection** by cutting Pin, Block and Delete and keeping only Skip and Move, which the prototype
places inside the expanded row.

## Files touched

39 files, +2,888 / −2,049.

| area | what |
|---|---|
| `resources/js/components/plan/` | nine new components: `SeasonHeaderCard`, `SeasonTimeline`, `WeekCluster`, `SeasonWeekRow`, `WeekVolumeChart`, `WeekDayRow`, `SessionBarGraph`, `MiniSessionBar`, `TemariTake` — each with a co-located test |
| | removed: `SeasonPhaseBar`, `SeasonWeekTimeline`, `DaySegments`, all subsumed by the nested timeline |
| `resources/js/lib/plan.ts` | shared payload types, the adherence mean, and the label maps both the page and the components read |
| `resources/js/pages/Plan.tsx` | rebuilt onto the prototype's section order (−694 lines net) |
| `resources/js/pages/Plan.test.tsx` | rebuilt against the new structure |
| `resources/js/types/inertia.ts` | `WeekPlanDay` gains `actual_km` and `activity` |
| `resources/js/components/ui/Icon.tsx` | four glyphs the timeline needs |
| `app/Services/Run/Plan/PlanRenderer.php` | `dayPayload()` emits `actual_km` + `activity` |
| `app/Http/Controllers/PlanController.php` | loads the rendered range's runs in one query |
| `app/Services/Run/Plan/SeasonSummaryBuilder.php` | per-week session count, `adherencePct()` |
| `app/Http/Requests/UpdatePlannedSessionRequest.php`, `routes/web.php` | P23's route and validation cuts |
| `docs/features/plan-periodizer.md`, `plan/ia.md` | kept true in the same commits |

## Blockers

None. `PP1` (shell, responsive), `PP2` (`FaceIcon`), `PP3` (Season Track, streak line and
`StreakPanel` already deleted) all landed first. P24's `SeasonHeaderCard` reuses the season-wide
aggregate `V0` fork 3 added.

## Acceptance criteria

- [x] Page follows `PlanScreen.tsx`'s section order: eyebrow, headline with the regenerate control,
      race-dependent intro, schedule/race tabs, season header card, timeline.
- [x] P22's nesting is complete: cluster → week → chart + day rows → per-day expansion.
- [x] P23: Skip and Move live inside the expanded day row; Pin, Block and Delete are unreachable
      (route and validation rule removed, not merely hidden).
- [x] P24: `SeasonHeaderCard` draws week X of N, adherence, phase bar, Temari's take. The season-goal
      card grid — the surviving half of the "Season Track" tier module — is cut, as is its "Badge
      board" link into a Trends block P25 removes.
- [x] Every new component has a co-located test (1:1 convention).
- [x] `Plan.test.tsx` restored, covering section order, the current week opening onto its chart and
      day rows, Skip and Move reaching the sessions endpoint, and the absence of the cut surfaces.
- [ ] Coverage delta recorded.
- [ ] `composer check` green on the final tree.

## Coverage delta

_To be recorded from `npm run test:coverage`._

## Verification notes

`docs/features/plan-periodizer.md` is named in `plan/README.md` §8 as the worst doc coupler in the
program — 24 code refs, and the citation guard is unconditional, so a stale ref reddens every open
PR rather than just this one. The guard caught two dead frontend refs here (`SeasonPhaseBar`,
`SeasonWeekTimeline`) and the surrounding prose had drifted further than that; it was rewritten in
the same commit as the deletions, per §8's own instruction.

Gate is `./vendor/bin/sail composer check` — the single command since `C1`, which now runs exactly
what CI runs.

## Open questions

1. **Move became a swap, not a re-date.** `planned_sessions` is unique on `(user_id, date)` and the
   periodizer materialises all seven days of every week, so the previous "just re-date the row"
   implementation hit that constraint on every in-horizon target — i.e. it was broken for the common
   case. Move now trades what the two days prescribe and pins both, and a move re-narrates both days
   rather than one. This is a behaviour change beyond a visual port; flagged so a reviewer sees it
   deliberately.

2. **`SeasonTimeline` grouped the season by phase *name*.** For a self-scaled season whose phase
   sequence passes through Build twice, that folded the second Build block's weeks into the first.
   It now splits on contiguous runs, so each pass through a phase is its own block. Found while
   writing tests, not by the port itself.

3. **`PlanDay` was a duplicate of `WeekPlanDay`** in `types/inertia.ts`. Consolidated to an alias,
   and `WeekPlanDay` gained the two fields `dayPayload()` had started emitting. Both callers send
   them, so this is Today's real payload too — which is why `Home.test.tsx`'s fixtures moved.
   `WeekPlanWidget` itself is unchanged. `PS3` should know this happened.

4. The adaptation verdict now lives as the current week's focus line, where the prototype puts its
   per-week focus text, rather than as its own card the prototype does not draw. Week and day
   narration moved into the week and day rows' "Temari's take"; the training disclaimer stayed but
   moved to the foot of the page.
