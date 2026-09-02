# T1 — The date picker

Raised by the user on 2026-09-02 with a screenshot of Chrome's native date popup: blue selection,
system font, a Clear/Today row, nothing like the rest of the form.

**This is an addition, not a port.** The prototype uses a native `type="date"` too
(`RaceGoalScreen.tsx:300`), so there is no drawn reference. **Ruled by the user**: a token-styled
calendar on pointer devices, the native input kept on touch, where the OS sheet beats anything we
would build.

## The input is kept, not replaced

The obvious implementation — swap the input for a button that opens a calendar — would have broken
validation silently. `RaceGoalForm` is a real `<form onSubmit>`, so the browser runs constraint
validation and blocks submission *before* the handler is reached: `required` and `min` on that
input are doing real work today.

So `DateField` keeps the native `<input type="date">` and layers on top of it:

- **Coarse pointer**: nothing changes at all. The native indicator and OS picker are left alone.
- **Fine pointer**: `::-webkit-calendar-picker-indicator` is hidden and our own trigger takes its
  place, opening a calendar popover that writes to the same state.

Either way the input still validates, still accepts a typed date, and still carries `min`.

Firefox draws its own indicator that `::-webkit-` cannot reach, so there it sits beside our
trigger. A cosmetic duplicate on one browser, against losing form validation everywhere — recorded
rather than chased.

## Scope

`RaceGoalForm` and `Onboarding`. **`UsageFilters` stays native**: it is the AI-usage operator page,
which P20 keeps outside the product surface.

New: `components/ui/DateField.tsx`, `hooks/useCoarsePointer.ts`, `lib/monthGrid.ts`, each with its
co-located test.

## Details worth recording

- **The grid is always six weeks**, so the popover never changes height as the month steps.
- **`addMonths` clamps** to the end of a shorter month, so stepping from the 31st lands in February
  rather than skipping it.
- **Day buttons are named by their full date**, not their number. A six-week grid shows two days
  numbered "5", so the number alone names neither of them — to a screen reader as much as to a
  test. Found by a test that could not disambiguate its own target.
- `useCoarsePointer` uses **`useSyncExternalStore`** rather than state-plus-effect, so the value is
  read during render — a touch device never paints a pointer-only affordance and then removes it —
  and a change landing between render and subscription cannot be missed. It started as
  state-plus-effect and eslint's `set-state-in-effect` rule was right to object. Where `matchMedia`
  is missing it reports **coarse**, keeping the native control: the option that always works.
- The weekday header carries full day names beside the initials, because the initials repeat
  (T/T, S/S) and so cannot key a list.

## Two bugs found on the way

**`Icon` renders nothing for a key its map does not carry.** That is deliberate, so a bad key can
never throw at a user, but it means a typo leaves a correctly-sized, correctly-coloured, *empty*
button. `mdi:calendar` is not a key — it is `mdi:calendar-blank-outline` — and the trigger shipped
invisible. Caught only by measuring the rendered SVG, not by looking at a screenshot.

So `resources/js/test/structure.test.ts` now asserts that every `mdi:` key referenced outside
`Icon.tsx` exists in the map. **The first version of that guard was vacuous**: its regex matched
only single quotes, and a JSX attribute writes double. It passed against a key already proven bad
by hand, which is how the hole was found — the guard was tested by breaking the thing it guards,
not by watching it go green.

The fixed guard then immediately found a **pre-existing** instance: `mdi:alert-outline` in
`RaceGoalForm` is not a key either (`mdi:alert-circle-outline` is), so the warning icon beside the
"ambitious goal" message has been rendering as nothing. Fixed here.

## Acceptance criteria

1. On a fine pointer the calendar opens, picks a day, writes it to the input, and closes; on a
   coarse pointer no custom trigger exists and the native indicator is untouched.
2. `required` and `min` still validate.
3. Every `mdi:` key in source resolves, enforced by a test that fails when one does not.
4. `./vendor/bin/sail composer check` green (`--no-tia` on pest).

## Verification notes

- **Both pointer paths were driven in a real browser**, not only in jsdom: a fine-pointer context
  opened the popover, picked a day, and the input value changed (`2026-11-25` → `2026-11-05`); a
  coarse-pointer context (`hasTouch`, `isMobile`) rendered no trigger and kept the native
  indicator. Zero console errors in either.
- The popover was **looked at** as well as measured, which is how the missing glyph was noticed as
  an empty gap in the field.
