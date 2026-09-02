# S2 — Onboarding

**Wave** 2b · **Slot** parallel worktree · **Blockers** `F4`, `B1` · **Status** merged ([#672](https://github.com/nukipratama/temari/pull/672), squashed as `8c8c804f`)

## Goal

Port `pages/Onboarding/Index.tsx` (300 L) against the prototype's `PreferenceControls.tsx` spec.
Coordinates with `B1`, which owns the backend shape of training preferences captured here.

## What actually landed

**The preferences step becomes a per-question sub-stepper**, matching the prototype's
`PreferencesStep`/`PreferenceControls.tsx` structure instead of the shipped page's single flat
card. Four new components carry this:
[StepProgress](../../resources/js/components/onboarding/StepProgress.tsx) (the persistent
Welcome/Training/Race Goal bar, with sub-dots for the four preference questions),
[IconChoiceCard](../../resources/js/components/onboarding/IconChoiceCard.tsx) (experience level,
goal type), [SessionsDial](../../resources/js/components/onboarding/SessionsDial.tsx) (sessions
per week) and [DayPicker](../../resources/js/components/onboarding/DayPicker.tsx)'s `DayRow`/
`DayCell` (run days + long-run day). Tapping an option auto-advances to the next question,
matching the prototype exactly; a back chevron revisits a prior question without discarding its
answer.

**The field shape is unchanged and needed no backend fork.** `experience_level` /
`sessions_per_week` / `goal_type` / `run_days` / `long_run_day` were already exactly `B1`'s
`TrainingPreference` shape on the pre-existing shipped page (`CompleteOnboardingRequest`,
`OnboardingController::store`) — this slice only restructures how those five fields are collected
client-side, submitting the identical payload shape as before.

**Every field stays independently optional, now at finer grain.** The shipped page's "Skip for
now" (discard every partial pick, jump straight to the race-goal step) is preserved verbatim as a
persistent header pill. New: a per-question "Skip this" link that leaves just that one field null
while keeping any other answers already given — a reasonable client-side addition, since
`CompleteOnboardingRequest` already validates each preference field independently nullable. The
days question is only reachable once a sessions-per-week target exists (there is nothing to pick
against otherwise); choosing to skip the sessions question routes straight past the days question
to the race-goal step, rather than showing a dead, disabled day-picker the way the old flat card
did.

**Selecting the long-run day now auto-advances straight to the race-goal step**, matching the
prototype's own `DayCell onClick={onFinish}` for that picker — a behavior change from the old flat
form, which required an explicit "Continue" click after picking a long-run day. Pure client
interaction, no backend implication; recorded here as a routine call, not a fork.

**The real Temari mascot renders throughout, not the prototype's generic `FaceIcon` placeholder** —
the shipped page already used `<Temari pose="glow" />` on the connected step before this slice
touched it, matching the precedent `S1` (Login) set of substituting the app's own mascot component
for the prototype's placeholder. The goal step's new decorative "required pace" ring (see below)
also centers a small `Temari pose="glow"` rather than `FaceIcon`.

**The goal step gained a decorative "required pace" ring**, ported from the prototype's
`GoalStep` — an SVG ring (`useCountUp`-animated fill) plus a big pace readout, computed purely
client-side from the distance/goal-time fields already on the form. Explicitly not a fitness
assessment (kept the prototype's own comment to that effect, trimmed to one sentence); no new
backend shape, matching the content-reconciliation rule for "a reasonable client-side addition over
data that already exists."

**`grounds.json` needed hand-updates for two new bg-\* fills (`icon-accent`, `border-strong`) and
one new translucent panel pairing** (R4/R8 in `plan/README.md` §7-8) — `DesignTokenContrastTest`
fails closed on any painted `bg-*` utility it can't classify. `border-strong` and `icon-accent` are
both pure fills (no `-ink`-suffixed text ever lands on them; `icon-accent` itself is `icon-accent`'s
own ground-reactive readable-text value, not a separate contrast concern). Discovered mid-build:
the first attempt used `bg-horizon/20` for the active/selected state on `IconChoiceCard`/`DayCell`,
which failed AA at that alpha (`horizon/0.2 + icon-accent` scored under 4.5:1); switched to
`bg-horizon/10`, matching this same page's own pre-existing "active pill" convention
(`border-horizon bg-horizon/10 text-horizon-ink` on the distance-preset buttons) and merged into
that already-registered, already-passing panel entry instead of adding a new one.

**`docs/features/onboarding.md` was stale before this slice touched it** — it described "two steps,
no persona/experience questions (deliberately out of scope)" despite the shipped page (pre-dating
this slice) already carrying the third, `B1`-backed preferences step. Brought current in the same
commit per the doc-freshness rule, describing the new sub-stepper structure and the pace ring.

## Files touched

New: `resources/js/components/onboarding/StepProgress.tsx` (+test), `IconChoiceCard.tsx` (+test),
`SessionsDial.tsx` (+test), `DayPicker.tsx` (+test).
Modified: `resources/js/pages/Onboarding/Index.tsx` (+test rewritten for the new sub-stepper flow),
`resources/js/components/ui/Icon.tsx` (4 new `ICON_MAP` entries: `mdi:sprout`, `mdi:target`,
`mdi:undo-variant`, `mdi:layers-outline`), `resources/brand/grounds.json` (fill classification +
panel entry, see above), `docs/features/onboarding.md`.

## Blockers

`F4`, `B1`. Both merged.

## Acceptance criteria

- [x] Preferences step restructured onto the prototype's per-question `PreferenceControls.tsx`
      spec (`StepProgress`, `IconChoiceCard`, `SessionsDial`, `DayPicker`), replacing the flat
      single-card layout.
- [x] No backend fork: the five training-preference fields submitted are byte-identical in shape
      to what `B1`'s `CompleteOnboardingRequest`/`TrainingPreference` already accept.
- [x] Every field stays independently optional — whole-leg "Skip for now" preserved verbatim, new
      per-question "Skip this" added without weakening validation.
- [x] Real Temari mascot used throughout (connected step, goal-step pace ring), not the prototype's
      `FaceIcon` placeholder.
- [x] UI chrome stays Title Case; no em-dashes in any UI copy string (caught and fixed one
      mis-transcribed em-dash in the days-question status line during implementation, replaced
      with `·`).
- [x] 1:1 test convention: 4 new co-located test files; `Onboarding/Index.test.tsx` rewritten for
      the new interaction flow, same final-payload assertions as before.
- [x] `grounds.json` updated for the new fills/panel pairing this slice paints (R4); the chosen
      tint passes AA on both grounds.
- [x] `docs/features/onboarding.md` brought current in this PR (was stale since before `B1`
      landed).

## Coverage delta

Backend: unaffected (no PHP touched). Full suite still 3737/3737 passing, 11418 assertions
(`bin pest --parallel --no-tia`).

Frontend: 218/218 → 222/222 test files (+4, the new components), 2115/2115 → 2132/2132 tests
passing. Coverage 95.62% → 95.68% statements, 89.37% → 89.48% branches, 95.47% → 95.54% functions,
95.98% → 96.03% lines (all four metrics net-positive; the 95% line+function gate cleared both
before and after). The new `components/onboarding/` directory is 98.33% statements / 92.59%
branches / 100% functions / 98.26% lines on its own; `pages/Onboarding/Index.tsx` is 100% across
statements/functions/lines and 96.49% branches.

## Verification notes

`pest --group=structure --no-tia` (38/38, after the `grounds.json` fix — first pass failed 3 closed
on unclassified `bg-icon-accent`/`bg-border-strong` and an unregistered `horizon/0.2` panel pairing,
then failed 1 closed on that pairing's own AA score once classified; both are recorded above), full
`bin pest --parallel --no-tia` (3737/3737, 11418 assertions), `npx tsc --noEmit` clean, `npm run
lint` clean (fixed two real findings mid-build: an import-sort ordering error, and a `DayRow`
rewrite off `Children.toArray`/index-keyed fragments onto an explicit `items` prop keyed by each
item's own React key, per `@eslint-react/no-children-to-array` /
`@eslint-react/no-array-index-key`), `npm run build && npm run check:chunks` green (Onboarding is
not one of the four hardcoded-budget routes; Login unaffected at 146.6 kB gz against its 160 kB
cap), `npm run check:palette` clean (467 files, zero off-token utilities), targeted
`npm run test` on the touched files green (52/52) before running the full suite.

**A resource-contention note, following `S11`'s precedent**: this worktree's stack shares the host
with two sibling worktree stacks (`s5-race-goal`, `s10-profile`) running concurrently
(`s10-profile-app-1` observed at 266% CPU via `docker stats`). The first two full
`npm run test:coverage` runs each showed exactly one failing test file, in both cases entirely
unrelated to this slice (`CardReveal.test.tsx`, then `AppShell.test.tsx` — both in the
celebration/card-reveal-vs-accessory-unlock timing area) and each different from the other, which
is itself evidence of flakiness rather than a real break; re-running `CardReveal.test.tsx` alone
passed cleanly (21/21). A third run, once `docker stats` showed the sibling stacks back at 0% CPU,
passed clean end to end: 222/222 test files, 2132/2132 tests. The coverage numbers above are from
that clean run (and its stashed-tree baseline counterpart), not the contended ones.

## Open questions

None blocking.
