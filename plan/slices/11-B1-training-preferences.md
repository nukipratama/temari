# B1 — Training preferences

**Wave** 2a · **Slot** worktree-be · **Blockers** `B2` · **Status** todo

## Goal

Experience level, sessions/week, goal type, run days, persisted long-run day.
`TrainingBaseline` becomes the fallback rather than the source. The prototype's
`PreferenceControls.tsx` is the UI spec — read at the frozen SHA, not adopted verbatim.

## Files touched

New migration + model fields for training preferences, `TrainingBaseline` (demoted to fallback),
onboarding flow (`OnboardingController`, `pages/Onboarding/Index.tsx` — coordinate with `S2`),
`resources/js/types/inertia.ts`.

## Blockers

`B2`.

## Acceptance criteria

_To be filled when wave 2a starts._

## Coverage delta

`n/a` — backend slice.

## Verification notes

_To be filled when wave 2a starts._

## Open questions

_To be filled when wave 2a starts._
