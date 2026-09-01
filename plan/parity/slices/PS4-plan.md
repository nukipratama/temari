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

44 files, +3,081 / −2,075.

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
| `tests/Feature/Http/Controllers/PlanControllerTest.php`, `tests/Unit/Http/Requests/UpdatePlannedSessionRequestTest.php` | P23's cuts, plus a real test of move-as-swap |
| `composer.json`, `plan/slices/31-C1-ci-guard-audit.md` | the `check`-gate process-timeout regression, fixed and recorded |
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
- [x] Coverage delta recorded.
- [x] `composer check` green on the final tree (`EXIT=0`, all 15 steps).

## Coverage delta

Measured `origin/epic/mobile-ux-port` (8415d1f7) vs the slice, same run configuration:

| | before | after |
|---|---|---|
| statements | 97.15% (3962/4078) | **97.30%** (4048/4160) |
| branches | 90.49% (3229/3568) | **90.80%** (3318/3654) |
| functions | 96.78% (1052/1087) | 96.77% (1082/1118) |
| lines | 97.54% (3769/3864) | **97.61%** (3841/3935) |

Up on statements, branches and lines; functions is flat to within a hundredth (−0.01pp, and +30
covered functions against +31 total). The slice is net **+82 statements** in the denominator — nine
new components and a new lib module against three deleted components and a much smaller `Plan.tsx` —
and it still moves the average up, which is the honest reading: the new code is better covered than
the code it replaces. Well clear of the 95% gate.

## Verification notes

`docs/features/plan-periodizer.md` is named in `plan/README.md` §8 as the worst doc coupler in the
program — 24 code refs, and the citation guard is unconditional, so a stale ref reddens every open
PR rather than just this one. The guard caught two dead frontend refs here (`SeasonPhaseBar`,
`SeasonWeekTimeline`) and the surrounding prose had drifted further than that; it was rewritten in
the same commit as the deletions, per §8's own instruction.

Gate is `./vendor/bin/sail composer check` — the single command since `C1`, which now runs exactly
what CI runs. **It could not complete before this slice**: composer's 300s default `process-timeout`
killed `phpstan analyse` mid-run, so the script exited 1 whatever the code did, and CI never saw it
because CI runs those steps separately and never invokes `composer check`. Fixed here (see the C1
slice doc's addendum), and the first end-to-end run promptly caught **seven** failures this slice
had otherwise been on course to open a PR with:

- five in `PlanControllerTest` — it still exercised `DELETE /plan/sessions/{id}` and the
  `session_type` block, both cut by P23;
- one in `UpdatePlannedSessionRequestTest`, same cause;
- one in `DesignTokenContrastTest` — `bg-text-3` painted but unclassified.

The last one also falsified this doc's own earlier claim that `grounds.json` needed no attention. It
now does not, for a better reason: the fill is `bg-ink-3`, the same value, already registered, and
the token `SectionLabel`'s dot already uses. That is a fair advertisement for running the gate rather
than assuming it.

`grounds.json` needs no regeneration: the timeline's cards use the existing `cardVariants` `card`
tone (`bg-card`), and the other new colour values are inline `style` fills on bars and dots, which
are graphic marks rather than registered panel grounds.

Ran in the `ps4-plan` worktree on slot 1 (ports 7011/7012), alongside `PS8` and `PS1`.

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

4. **The adaptation verdict now lives as the current week's focus line**, where the prototype puts
   its per-week focus text, rather than as its own card the prototype does not draw. Week and day
   narration moved into the week and day rows' "Temari's take"; the training disclaimer stayed but
   moved to the foot of the page.

   The prototype's `focus` is a hand-written sentence on *every* week. The app has no such field,
   and the nearest real data — the `PlanAdaptation` verdict — exists only for the current week. So
   past and future week cards carry **no focus line** rather than a fabricated one. Recorded as a
   deliberate gap, not an oversight.

5. **Past weeks show no "Temari's take", where the prototype shows one on every done week.** The
   app narrates only the current week's seven days plus the current week and the season
   (`PlanNarrationRequester::payloadsForCurrentWeek()`). Serving past weeks would mean rendering
   `Analysis::toPayload(null, …)` for each, which is a *pending* block with a live retry affordance
   — one LLM call per historical week, triggerable by a reader who thinks it is just a spinner. Left
   as-is deliberately; extending week narration backwards is a billing decision, not a parity one.

6. **Two P2 (token-nearest) calls worth a reviewer's eye.**
   - **Phase colours** stay the app's validated `PHASE_COLORS` (`chartTokens.ts`), not the
     prototype's raw `#0d9488/#1e6fe0/#a21caf/#db2777`. The app's set is CVD-validated, is already
     used elsewhere, and covers the fifth phase (`deload`) that a self-scaled season has and the
     prototype's fixed four do not.
   - **Card radius**: the timeline's cards use `cardVariants` (`rounded-md` = 14px), which is what
     the prototype's `rounded-[14px]` snaps to. The Plan page previously used the shadcn `Card`
     (`rounded-4xl` = 26px). This slice is the first screen port, so it sets the precedent — if the
     other ten slices should follow, that is a cross-screen call, not one PS4 can make alone.

## Plan / prototype discrepancies found

`reference.md`'s Plan section (§6) held up against `PlanScreen.tsx` on every point I checked —
section list, line citations, the nesting diagram, the interaction table, the `canMove`/`canSkip`
guards, and "Explicitly absent". No corrections needed to it.

Two inconsistencies **inside the plan**, both resolved in favour of the decision text:

1. **`cut-list.md` §1 and P24 disagree about the season-goal grid.** The cut-list's "Plan: Season
   Track tier module" row records `PP3` as having cut the `SeasonTrack` rail while *keeping* the
   `GoalCard` grid. P24 and `reference.md`'s "Explicitly absent" both say the 5-goal-tier module
   goes and that the only tiered element is the 4-phase bar. The prototype draws no goal cards on
   Plan at all, so P1 settles it: the grid is cut here. `season.goals` still ships from the server
   and is now unread by Plan — a `W2` orphan, per P4's UI-and-routes-only depth.

2. **P24 says season goals survive "as the prototype's single headline line".** There is no such
   line on the prototype's Plan screen; `SeasonHeaderCard` draws week/adherence/phases/narration and
   nothing about goals. The phrase fits Profile's small `SeasonCard`, which is `PS10`'s. Read that
   way, nothing is owed here.
