# PP5 — The dark-ground `horizon-ink` bug, and the calendar's Today button

Not a parity item. A **bug in merged work**, reported by the user against the running app on
2026-09-02: green text renders as a dark olive on the dark ground in some places and as the vivid
lime in others. Named examples: Plan's "ahead.", "temari's take", the season adherence figure, and
"change your race".

Carries one unrelated cut the user asked for in the same message.

## 1. `--color-horizon-ink` has no dark counterpart

**Measured, not inferred.** On `/plan` with `data-theme="dark"`, `--color-horizon-ink` resolves to
`#546d23` and "ahead." computes to `rgb(84, 109, 35)` against a `#0b1017` background. That is
**~2.9:1** — under AA, and visibly muddy, which is what the user saw.

`F2` inverts the `-ink` tier for the dark ground, deriving each value through `inkOnDark()`. It
excluded `horizon` on an explicit and stated reason, in `build-tokens.mjs`, in `app.css`, and again
in `DesignTokenContrastTest`:

> horizon-ink itself needs no dark counterpart: the app already swaps to the vivid
> `--color-horizon` fill for icon-accent/btn-primary-bg on dark

**That premise is false.** `resources/js` holds **33** `text-horizon-ink` call sites — Plan's
headline and race link, `TemariTake`, `SeasonHeaderCard`'s adherence figure, `WeekCluster`,
`WeekVolumeChart`, `WeekDayRow`, Profile's `SeasonCard`/`ProgressionCard`/`ProfileHero`/`RaceCard`,
`HistoryHeader`, `AnalysisStatus`, `BackLink`, `ReadMoreToggle`, `StravaSyncBadge`, `EmptyRunsState`,
`CoachMark`, plus `lib/tones.ts`, `lib/plan.ts` and two `variants.ts` entries. Every one of them
renders `#546d23` on Sky.

The fix is the one line the premise assumed was already true: `horizon` joins
`DARK_INK_FAMILIES`. `inkOnDark()` returns the vivid fill unchanged for it, so the dark value is
`#ade047` — identical to `icon-accent`, which is what the reasoning believed was in use.

### Why CI was green

This is **R1's predicted failure mode**, arriving eighteen slices late. `DesignTokenContrastTest`'s
dark-ink check iterates the tokens the dark block **redefines**:

```php
$inks = array_filter($darkTokens, fn ($name) => str_ends_with($name, '-ink'), ARRAY_FILTER_USE_KEY);
```

A family left out of that block is therefore never scored — the guard cannot fail on a token that
is not there. Excluding `horizon-ink` from the dark layer simultaneously created the bug and
removed the only check that would have caught it.

So the check now asserts the **roster** rather than iterating whatever happens to be present. A
future omission fails loudly instead of disappearing. `build-tokens-dark.test.ts` covers the
derivation side and picks up the fourth family automatically.

### A stale artifact found on the way, not touched

Running `node resources/brand/build-tokens.mjs` writes `resources/brand/tokens.css` and
`tokens.html`, and both still hold the **pre-Pewter gold-and-indigo palette** — `--color-horizon:
#d9a53c`, `--color-sky: #241c54`. Nothing reads either file; the shipped `@theme` lives in
`app.css`. Regenerating them here would have buried a one-token fix under a whole-palette diff, so
they are left exactly as they were and flagged for `W2`'s dead-code sweep instead.

## 2. The calendar's Today button

`Calendar.tsx`'s month nav carried a "Today" pill that appeared whenever the viewed month was not
the current one. The prototype's calendar has no such control — it marks the current day on the
grid and gives the header nothing but the two chevrons and the month name. Cut at the user's
request, which agrees with the prototype. `MonthNav` loses the `showTodayButton` prop with it;
`isCurrentMonth` survives, still gating the monthly recap's `awaitingSchedule`.

## Acceptance criteria

1. `--color-horizon-ink` resolves to `#ade047` on the dark ground, verified in the browser.
2. `DesignTokenContrastTest` scores it there, and fails if a family is dropped from the dark block.
3. The Today button is gone and nothing else in the month nav moved.
4. `./vendor/bin/sail composer check` green (`--no-tia` on pest).

## Verification notes

- The bug was **measured before being fixed and after**: `#546d23` / `rgb(84, 109, 35)` before,
  `#ade047` / `rgb(173, 224, 71)` after, on the same element on the same page.
- The light ground is untouched — `horizon-ink` stays `#546d23` there, which is the value it was
  derived for.

## Open questions

1. The user also asked for the **date picker** to match the rest of the UI. The screenshot is
   Chrome's native `<input type="date">` popup, which CSS cannot theme — and the prototype uses a
   native `type="date"` too (`RaceGoalScreen.tsx:300`), so there is nothing to port. Making it
   consistent means *adding* a component the prototype does not draw, across three inputs
   (`RaceGoalForm`, `Onboarding`, `UsageFilters`). **Ruled by the user 2026-09-02**: a token-styled
   calendar popover on pointer devices, the native input kept on touch, where the OS sheet beats
   anything we would build. Its own slice, not this one.
2. The **favicon and app icons** are the pre-Pewter bunny on the old indigo/gold palette
   (`public/favicon.svg`, plus five PNGs dated before the rebrand). The user asked for the new
   `TemariMark`. Also its own change, not this one.
