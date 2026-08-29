# B1 — Training preferences

**Wave** 2a · **Slot** worktree-be · **Blockers** `B2` · **Status** merged ([#662](https://github.com/nukipratama/temari/pull/662), squashed as `c9338293`)

## Goal

Experience level, sessions/week, goal type, run days, persisted long-run day.
`TrainingBaseline` becomes the fallback rather than the source. The prototype's
`PreferenceControls.tsx` is the UI spec — read at the frozen SHA, not adopted verbatim.

## What actually landed

**A new `training_preferences` table, 1:1 with `User`, every column nullable.** Mirrors the
`RunnerProfile`/`RaceGoal` own-table precedent rather than bolting five columns onto `users`.
[TrainingPreference](../../app/Models/TrainingPreference.php) stays nullable-until-set on every
field — clearing a field back to `null` hands control back to the behavioral fallback, not to some
other stored default.

**`TrainingBaseline` now resolves preference-over-behavior internally, not at each call site.**
[TrainingBaseline::forUser()](../../app/Services/Run/Plan/TrainingBaseline.php) keeps its
`(User, Carbon): array` signature exactly as-is — it queries `TrainingPreference` itself, the same
way it already queries `WeeklySnapshot`/`ActivityDetail` directly. An explicit `sessions_per_week`
always wins over the trailing-4-week average, and — the one deliberate asymmetry — bypasses the
old 3-6 behavioral clamp entirely: 2 is a valid *explicit* choice, just never a valid *inferred*
one (nothing about noisy week-to-week variance should ever land an inferred value below 3). With
zero logged weeks and no explicit `sessions_per_week`, a stated `experience_level` seeds which
cold-start `[sessions, km]` pair to use (`new_to_running` 3x/12km, `returning` 4x/20km,
`experienced` 5x/35km) instead of every brand-new athlete getting the identical flat default
regardless of what they claim. Real logged behavior always wins the moment any exists, full stop —
this was the one piece worth stress-testing, and
[TrainingBaselineTest](../../tests/Unit/Services/Run/Plan/TrainingBaselineTest.php) has a dedicated
case for it. Centralizing this in `TrainingBaseline` rather than threading `TrainingPreference`
through every one of its five call sites (`SeasonService`, `CurrentWeekPlanBuilder`, `Periodizer`,
`PlanController`, `ScoreComplianceCommand`) meant **zero signature changes and zero call-site
changes** anywhere except the one place that actually needed new inputs (see next).

**`goal_type` carries no computational weight in this slice.** `RaceGoal`'s presence/absence
already fully selects the periodization mode (race-oriented Base/Build/Peak/Taper vs. perpetual
self-scaled Build/Deload) — a re-derivation of that from `goal_type` would be redundant, not
additive. It's stored as real signal (`consistent`/`race`/`base`/`return`) for a future narration
slice to read; `Periodizer`, `WeekPlanBuilder` and `TrainingBaseline` never look at it.

**`run_days`/`long_run_day` are a hard override of `WeekPlanBuilder::DAY_TEMPLATES`, not a
day-of-week analogue of `TrainingBaseline`'s behavioral fallback.** There's no behavioral
equivalent to infer a preferred weekday from — nothing in the app computes "which day does this
athlete usually run on" — so this pair lives outside `TrainingBaseline`'s contract entirely.
[Periodizer::regenerate()](../../app/Services/Run/Plan/Periodizer.php) fetches the
`TrainingPreference` row once and threads `run_days`/`long_run_day` straight into
[WeekPlanBuilder::build()](../../app/Services/Run/Plan/WeekPlanBuilder.php)'s two new trailing
optional params: when both are set, they replace the day template outright (the chosen weekdays
become the literal training days every regenerated week, the flagged day always the long run);
`DAY_TEMPLATES` stays the fallback otherwise. `WeekPlanBuilder` itself gained a real `2 => [2, 5]`
template entry (Wed/Sat, long run Saturday — matching the existing 3/4-session convention rather
than inventing a new one) and `MIN_SESSIONS` widened from 3 to 2, so the prototype's full
`SESSIONS_OPTIONS = [2, 3, 4, 5, 6]` range is genuinely supported end to end, not just accepted and
silently clamped back up.

**One real coupling bug found and fixed along the way, not a scoring bug.**
`SeasonService::generateGoals()` had its own `max(3, min(6, ...))` re-clamp on top of
`TrainingBaseline`'s already-resolved `sessions_per_week` — harmless before this slice (the
behavioral value was always already in 3-6), but it would have silently clamped an explicit 2x/week
preference back up to 3, so a season's `season_sessions_completed` target would disagree with what
`Periodizer` actually scheduled. Removed; `SeasonServiceTest` gained a case pinning a 2x preference
through to the season goal's target.

**Onboarding gains a real preferences step, and Settings gains a real edit surface — both plain,
neither prototype-styled.** Following the precedent B2 set (a functionally-complete but plainly
styled Skip button on `Plan.tsx`, well before S4's redesign): `Onboarding/Index.tsx` gained a new
`'preferences'` step between `'connected'` and `'goal'` (now step 2 of 3), collecting all five
fields on one screen with plain toggle-button groups — matching this same file's own established
"goal" step pattern (everything on one screen, not sub-stepped) rather than mimicking the
prototype's four-question swipe-through wizard. The whole step is skippable, and skipping discards
any partial picks rather than submitting them half-finished. A new
[TrainingPreferencesDisclosure](../../resources/js/components/settings/TrainingPreferencesDisclosure.tsx)
component mirrors `HrZonesDisclosure`'s expand/collapse/save pattern exactly, wired into Settings'
existing "Running" section alongside HR zones. Both surfaces share the same day-count-capped,
long-run-picker-reveals-once-complete interaction. New `PATCH /settings/training-preferences` →
[TrainingPreferencesController](../../app/Http/Controllers/TrainingPreferencesController.php),
mirroring `RunnerZonesController`'s `updateOrCreate` shape exactly.

**No `resources/js/types/inertia.ts` changes, despite the stub listing it.** `TrainingPreferencesPayload`
is defined and exported from `TrainingPreferencesDisclosure.tsx` itself, the same way
`HrZonesPayload` is exported from `HrZonesDisclosure.tsx` rather than living in the shared
`inertia.ts` file — it's a Settings-page-only prop shape, not part of `WeekPlanDay`/`SharedProps`.

**Shared cross-field validation, one shape, two call sites.**
[CompleteOnboardingRequest](../../app/Http/Requests/CompleteOnboardingRequest.php) (onboarding) and
[UpdateTrainingPreferencesRequest](../../app/Http/Requests/UpdateTrainingPreferencesRequest.php)
(Settings) both validate the same five fields with the same `withValidator()`-based structural
check: `count(run_days)` must equal `sessions_per_week` when both are present, and `long_run_day`
must be a member of `run_days` — mirroring `UpdateHrZonesRequest`'s own cross-field-check shape.
Every field stays independently nullable in both requests; the check only fires once `run_days` is
actually submitted.

## Files touched

New: migration `2026_08_29_231031_create_training_preferences_table.php`,
`app/Models/TrainingPreference.php` (+test), `app/Enums/ExperienceLevel.php`,
`app/Enums/GoalType.php`, `app/Http/Controllers/TrainingPreferencesController.php` (+test),
`app/Http/Requests/UpdateTrainingPreferencesRequest.php` (+test),
`database/factories/TrainingPreferenceFactory.php`,
`resources/js/components/settings/TrainingPreferencesDisclosure.tsx` (+test).
Modified: `app/Services/Run/Plan/TrainingBaseline.php` (+test), `app/Services/Run/Plan/WeekPlanBuilder.php`
(+test), `app/Services/Run/Plan/Periodizer.php` (+test), `app/Services/Run/Plan/SeasonService.php` (+test),
`app/Models/User.php`, `app/Http/Requests/CompleteOnboardingRequest.php` (+test),
`app/Http/Controllers/OnboardingController.php` (+test), `app/Http/Controllers/SettingsController.php` (+test),
`app/Console/Commands/GenerateTypeScriptEnumsCommand.php`, `routes/web.php`,
`resources/js/types/generated.ts` (regenerated), `resources/js/pages/Onboarding/Index.tsx` (+test),
`resources/js/pages/Settings/Index.tsx`, `resources/brand/grounds.json`,
`docs/features/plan-periodizer.md`.

## Blockers

`B2` — one worktree slot, strictly sequential per wave 2a's `B3 → B2 → B1 → B4` order.

## Acceptance criteria

- [x] `experience_level`, `sessions_per_week`, `goal_type`, `run_days`, `long_run_day` persist on a
      new 1:1 `training_preferences` table, every field independently nullable.
- [x] `TrainingBaseline` reads an explicit `sessions_per_week` over the behavioral average, and an
      `experience_level` seed over the flat cold-start default, with zero call-site changes.
- [x] `WeekPlanBuilder` supports the prototype's full 2-6 session range, including a real 2-day
      template, and accepts an explicit `run_days`/`long_run_day` override.
- [x] The whole step is skippable at onboarding; no backfill needed for existing users.
- [x] A Settings edit surface exists, separate from onboarding, following the `RunnerZonesController`
      shape.
- [x] `goal_type` is stored but wired to nothing computational — deliberately, not an oversight.

## Coverage delta

Backend: full suite 3686/3686 passing (up from 3649 pre-slice, +37 new tests across
`TrainingBaselineTest`, `WeekPlanBuilderTest`, `PeriodizerTest`, `SeasonServiceTest`,
`CompleteOnboardingRequestTest`, `OnboardingControllerTest`, `SettingsControllerTest`, plus the 3
new-file test suites for `TrainingPreference`/`TrainingPreferencesController`/
`UpdateTrainingPreferencesRequest`). Frontend: global coverage 95.55% statements / 89.28% branches /
95.37% functions / 95.92% lines — a real fight this time, not a comfortable margin (see Verification
notes).

## Verification notes

`pest --group=structure --no-tia` (37/37 — caught one real miss: the new
`TrainingPreferencesDisclosure.tsx`'s `bg-horizon/10` panel was unregistered in
`resources/brand/grounds.json`, fixed by adding it to the existing `horizon/0.1` entry's `over` map
alongside the sibling call sites that already use the identical class), `bin phpstan analyse
--debug` (0 errors — caught an unnecessary-nullsafe false positive from a dense nested-ternary in
`TrainingBaseline::forUser()`, resolved by restructuring to explicit if/elseif rather than
suppressing), `bin pint` / `bin rector --dry-run` clean (rector caught two real `array_map('intval',
...)` call sites that needed the first-class-callable form), full `bin pest --parallel --no-tia`
(3686/3686), `npx tsc --noEmit` clean, `npm run build && check:chunks` green, `npm run
test:coverage` green only after real work: the first run failed on functions (94.68% vs. 95%,
global — not per-file). Root cause was genuine undertesting, not padding-worthy: `submit()`'s
`onStart`/`onFinish`/`onSuccess` callbacks were never invoked (the router mock doesn't call them —
fixed by capturing the mock's call args and invoking them manually, the same pattern
`HrZonesDisclosureTest` already used, but wrapped in `act()` this time since these particular
assertions check DOM state a manual callback invocation doesn't auto-flush); the day-toggle's
remove branch and the long-run-day-picker's own click handler were never exercised; and — a real
dead-code find — `toggleRunDay`'s cap-guard early-return was provably unreachable via the UI (the
day button is already `disabled` before that branch could fire), so it was simplified away rather
than chased with a contrived test, in both `TrainingPreferencesDisclosure.tsx` and
`Onboarding/Index.tsx` (they duplicate the same toggle). Separately, the existing "submits the goal
with distance..." Onboarding test was setting Hours to `'0'`, its own already-current default value
— a React controlled-input testing-library gotcha where `fireEvent.change` to an unchanged value
never fires `onChange` at all, so `setHours` had silently never run in ANY test run of this file,
pre-B1 included; fixed by changing the test to a genuinely different value.
`check-raw-palette.mjs` / `check-doc-citations.php` / `check-see-references.php` clean.

## Open questions

None blocking. `goal_type` has no consumer yet — same "deferred, no UI need identified" treatment
B2 gave per-day compliance overrides; the natural consumer is a future narration slice (`B4` is
voice-only and doesn't currently read it either). The prototype's four-question swipe-through wizard
was deliberately not adopted for the plain onboarding/Settings UI in this slice — both single-screen
instead — `S2`/`S11` own the decision of whether to bring the swipe-through interaction back when
they restyle onto the prototype's actual `SessionsDial`/`IconChoiceCard`/`DayRow` components.
