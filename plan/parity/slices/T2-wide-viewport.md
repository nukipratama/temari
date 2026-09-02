# T2 — The content column and type scale at large viewports

Raised by the user on 2026-09-02 against a ~2324px screenshot: the page reads mostly empty. Then,
mid-implementation: "the font on larger viewport should be bigger right".

**This amends the corrected P5/P31**, whose 760px is the prototype's own container-query value and
was adopted deliberately. It is not a bug fix.

## Why the prototype cannot settle it

Its `PhoneFrame` never renders wider than the frame, so above 900px there is no drawn reference at
all. Whatever we choose here is ours, and this doc is the only record of it.

For scale: before the port this app used **1440px**, easing to 1680 at 2xl. Those tokens are still
in `app.css` and the two operator pages still use them (P20 leaves those alone). The port dropped
1440 → 760 in one step, which is the whole of what the user is seeing.

## 1. The column

**Ruled by the user**: keep the single centred column, add one more step.

| viewport | column |
|---|---|
| below 900px | full width, as drawn |
| 900px | **760px** — the prototype's own value, unchanged |
| 1280px | **1040px** — ours |

Rejected: a two-column card grid (a layout the prototype never drew, per-screen work, and each
screen would need a reading-order decision), selective break-out of wide blocks only (ragged edge,
page still reads narrow), and restoring 1440 (sized for the old denser design; the ported cards and
day rows would stretch very wide).

**The width was a literal in nine places** — `PageContainer`, the nav pill, and six banners — so
it becomes `--container-column` / `--container-column-wide`. The banners have to track the column
or they visibly drift out of alignment with the content they sit above.

**The nav pill tracks the column at both steps.** It was first shipped capped at 760 on the
reading that P32's objection — four items spread across a wide track read as far apart — carried
over to 1040. **The user overruled that on sight**: 1040 is still a bounded column rather than the
full-bleed track P32 was written about, and a pill visibly narrower than the content above it is
the more obviously wrong of the two. Asserted in its own test.

Onboarding's 520px and Login's 440px auth card keep their prototype values. Onboarding overrides
`max-w` through `className`, which tailwind-merge keeps *alongside* a differently-prefixed
`min-[1280px]:` utility rather than replacing it, so it needs its own explicit `min-[1280px]` cap —
without it the wizard would have jumped to 1040.

## 2. The type

**Measured first.** From 900px to 2324px nothing changed at all: heading 22px, body 14px, day name
14px, micro label 11px, at every width. The display and headline tiers are already fluid on `vw`
but hit their `clamp()` ceiling well below 1280, so they were never the issue.

The obstacle was how the port was done. Body and label sizing split two ways: **87 files** on
rem-based `text-xs/sm/base`, and **91 sites** on px literals (`text-[11px]`, `text-[13px]`,
`text-[9.5px]` …) ported 1:1 from the prototype. A root font-size step scales the first group and
not the second, so on its own it would have left the UI visibly mismatched at exactly one
breakpoint.

**Ruled by the user**: convert the px literals to rem, then step the root once.

- All 91 `text-[Npx]` sites become `text-[Nrem]`. Every value is exact — the sizes are all
  N/16 with no rounding — so **below 1280 nothing moves at all**.
- The same conversion applies to `app.css`'s three label-role utilities and to the fixed-px type
  tokens `--text-quote-lg/md/sm` and `--text-stat`.
- `html { font-size: 19.2px }` at 1280px, a **20%** step, at the same breakpoint the column widens.
  It shipped at 10% first and the user called it still too small against a real screen; 20% is the
  second reading and the knob is one token.
- **The spacing scale had to come with it, and did not at first.** This slice originally claimed
  "Tailwind's spacing scale is rem too, so padding and gaps grow with the type" — **that was
  wrong**. This theme names ten steps of its own, `--spacing-1` through `-16`, and declared every
  one in **px**; each step it does *not* name falls through to Tailwind's rem-based default. So the
  scale was half px and half rem, which agreed exactly at a 16px root and stopped agreeing the
  moment the root stepped. The ten named steps and the five `--pad-*` roles are now rem. Identical
  below 1280, scaling with everything else above it.

**The comment saying the quote tier must not scale is corrected, not ignored.** It reads "body
reading should NOT scale with viewport because the focal distance is constant" — which is an
argument against `vw` fluidity, where a phone and a monitor at the same fraction render at wildly
different physical sizes. A discrete step at a desktop breakpoint is that same argument pointing
the other way: a desktop *is* read at a longer focal distance. The comment now says so.

**Media queries resolve `rem` against the initial 16px, not the root's**, so Tailwind's own
rem-based breakpoints do not move under the step. Verified rather than assumed: the column still
changes at exactly 1280 and 1279 is untouched.

## Files touched

`resources/css/app.css` (two container tokens, the type-token conversions, the root step),
`components/ui/PageContainer.tsx`, `components/MobileBottomNav.tsx`, the six banner components,
`pages/Auth/Login.tsx`, `pages/Onboarding/Index.tsx`, plus 43 files in `resources/js` for the
literal conversion and three co-located tests.

## Acceptance criteria

1. Below 1280px, every measured value is identical to before.
2. At 1280px the column is 1040 and the root is 17.6px; the nav pill stays 760.
3. Onboarding stays 520 and Login's auth card stays 440 at every width.
4. No horizontal overflow at any viewport.
5. `./vendor/bin/sail composer check` green (`--no-tia` on pest).

## Verification notes

- **Measured at five widths before and after** — 390 / 900 / 1279 / 1280 / 2324 — not inferred from
  the CSS. The 1279 row is the proof that the conversion was value-preserving.
- The three failing tests were updated **from the suite's own output**, one at a time. No blanket
  rewrite: the conversion regex matched `text-[Npx]` only, so it could not touch a spacing literal,
  a colour, or an identifier.

## The chart the user caught

`WeekVolumeChart`'s bar row pinned `h-16` around a column of `h-14` track + `gap-1` + weekday
label. Measured, that content is **77px inside a 64px box** — so it has spilled over the legend
above it since the day it was written, at every viewport, root step or no root step. The type step
did not cause it; it widened the spill to 27px and made it obvious.

The row no longer pins a height: its content defines one, which is what it always should have done.
Verified by measurement (`scrollHeight - clientHeight` now 0 at both roots) and by looking at the
rendered card.

**Swept for siblings**, since one instance of this pattern implies others: every element on the
nine main pages whose content overflows a pinned height with `overflow-y: visible`. Nine pages,
zero further hits. The two units disagreeing was the systemic half of the bug, and that is fixed at
the token; this component was the only place also carrying the local half.

## What the audit caught

**Activity detail overflowed at 1536px**, and it was this change that caused it.
`CoachMark` positions itself in JS against a hardcoded `WIDTH = 256` while rendering at `w-64`.
Those two agreed exactly until the root font size stepped, at which point `16rem` became 281.6px
and the clamp was short by the difference — so the mark landed 5px past the viewport edge.

A pre-existing latent coupling, not a new bug: a JS constant mirroring a CSS class, with nothing
holding them together. The width is now applied *from* the constant and the `w-64` is gone, so the
two cannot drift again. Re-measured: no overflowing element, and `audit.mjs` reports zero overflow
across all 13 routes at both 1280 and 1536.

Worth stating plainly: **the automated audit found this and a screenshot read would not have.**
`document.scrollWidth` was 1536 — the page did not scroll — and the mark is a transient popover
that a sweep would not have had open. Only the per-element bounds check saw it.

## Also cut here: the top bar's Strava sync badge

Asked for by the user on sight. It is a parity cut as well as a preference: the prototype's
`AppTopbar` draws the wordmark, a bell and an avatar, and nothing else — P7's list already says so.

**The badge was not purely informational**, which is the part worth checking before cutting. Its
`revoked` state was a live `/auth/strava/redirect` link, so removing it removes a reconnect
affordance. That path survives in two other places, and neither banner is one of them:
`StravaPausedBanner` is gated on the operator kill-switch and is action-less, and
`StravaZoneReconnectBanner` on a *live* connection missing the zones scope. What does cover it is
**Profile's hero action**, gated on exactly `stravaSync?.state === 'revoked'` — which the topbar's
avatar leads to — and `StravaSyncButton` on the feed's empty state. So a revoked user still has a
way back.

The component and its test are deleted rather than left unused. The `stravaSync` shared prop stays;
Profile and the feed still read it. Two `grounds.json` registrations were orphaned by the deletion
(`sky/0.06`'s call site and the `sky/0.12` hover tint) and removed.

## Open questions

1. **The step is a single 10% knob.** It buys coherence at the cost of per-tier control: a future
   "make only the headings bigger on desktop" is no longer a one-line change. Accepted knowingly —
   the alternative was ~90 sites silently opting out of the rule.
2. **A new `text-[Npx]` literal would silently stop scaling.** Nothing guards it. A
   `check:palette`-style rule rejecting px font-sizes in `resources/js` would close that, and is
   worth considering with the other guards in `W4`.
