---
title: The ink tier is scored against grounds derived from the render, not a written list
description: Every -ink token is derived and audited against the grounds the app actually paints — read from the stylesheet and from the components — and a background nothing classifies fails the build instead of being skipped.
tags: [decision, design]
status: accepted
reviewed: 2026-08-14
code_refs:
  - resources/brand/grounds.mjs
  - resources/brand/grounds.json
  - resources/brand/build-tokens.mjs
  - resources/css/app.css
  - resources/js/lib/designTokens.ts
  - tests/Unit/Architecture/DesignTokenContrastTest.php
---

# The ink tier is scored against grounds derived from the render, not a written list

**Status:** Accepted (documented 2026-08-14)

> **2026-08-31 — one fact below has changed, the decision has not.** `PP3` cut dawn-shift
> (`plan/parity` decision P17): the light ground is one static palette and no
> `body[data-time-of-day]` rule remains. The five drifting `--color-surface` values the Context
> describes are history. The derivation itself is unchanged and still enforced — the ground set is
> now every background `grounds.json` classifies as paper, scraped from the components rather than
> listed.

## Context

The fill/text split gives every saturated family a derived `-ink` member, darkened
until it clears 4.5:1 "on paper". The **method** was right and still is: target the
darkest ground the app can render. What was wrong, three times running, was the
answer to *which grounds those are*.

The ground set was a literal in the generator: the five `--color-surface` values
dawn-shift drifts between. `AppShell` paints `bg-cream-deep`
([AppShell.tsx](../../resources/js/layouts/AppShell.tsx):55), and `--color-cream-deep`
is `#ece2ce` — **darker than all five**, and absent from the list. So the entire
hue-derived ink tier shipped at **4.28–4.34:1** on the ground under the whole app,
while the generator, the CI test and `/devtools/design` all reported a pass. Each
audit had independently copied the same five-entry list.

Two further grounds were never scored by anything:

- **the tinted mood cell.** `mood-*-bg` was declared straight into `app.css` and
  never passed through the generator at all. `mood-wobbly-ink` measured **4.16:1**
  on `mood-wobbly-bg`, the only ground it is ever printed on.
- **the alpha tint.** A chip painted `bg-<family>/<alpha>` prints on the tint, not
  on the paper beneath it, and the tint is darker than any paper. Measured in a
  browser: `horizon-ink` **3.99:1** on `/accessories`, `mood-easy-ink` **3.92:1**
  on `/activities` — both while every opaque ground passed.

A list cannot notice a ground nobody added to it. That is the defect, not the five
particular values.

## Decision

**Nothing writes grounds down.** The ground set is derived, from the two places
that decide it:

- **values** come out of the shipped stylesheet — the `@theme static` block and the
  `body[data-time-of-day]` rules ([grounds.mjs](../../resources/brand/grounds.mjs));
- **the set of backgrounds in play** comes out of the components: every `bg-*`
  utility `resources/js` actually paints.

[grounds.json](../../resources/brand/grounds.json) records only what *kind* each
background is — `paper` (any ink lands on it), `scoped` (only its own family's ink
does), `fill` (carries no ink text), `keyword` (not a `--color-*` token) — plus the
heaviest alpha tint painted under each family's ink.

An `-ink` token is then derived and scored against: every paper ground, its own
`-bg` cell when it paints one, and its heaviest tint composited over the darkest
paper. The last two come from the `-ink`/`-bg`/family naming convention, so a new
cell or a heavier tint is scored the moment it is recorded — no second list.

**And it fails closed.** A `bg-*` utility in use that `grounds.json` does not
classify makes the generator throw and `DesignTokenContrastTest` go red; a recorded
tint that drifts from the source goes red; a classified ground the live stylesheet
no longer resolves scores `null` on `/devtools/design` and reports as a failure
rather than quietly shrinking the set. The shape is
[[demo-user-billing-exclusion]]'s: enumerate the live thing, and fail on anything
unaccounted for.

The three audits now share `grounds.json` instead of each keeping a copy of the
answer, which is what let them drift into agreeing while all three were wrong.

## Consequences

Ten tokens moved, all text or separator values; every vivid fill is byte-identical.
Hue separation was expected to flatten and did not — the largest channel shift is
11/255, because the gap being closed was about a quarter of a contrast point, not a
tier. Every `-ink` token now measures ≥4.5:1 on every ground the app paints it on,
verified by sampling rendered pixels in a real browser rather than only in the
generator.

Two limits are deliberate and worth naming, because both are invisible to a static
audit:

- **Nested tints.** Two tints of the same hue stack, and the parent is only known at
  render time. The one instance found (an `Equipped` chip inside its own card's gold
  wash) was removed at the call site rather than modelled; a future one will need the
  browser to catch it.
- **`-deep` fills used as text.** `leaf-deep`, `ember-deep` and `citrus-deep` are CTA
  *fills*, but 85 call sites use them as label colours because the semantic accents
  have no `-ink` member. They are outside this tier and therefore unscored — measured
  `citrus-deep` at **2.96:1** on the page ground and `leaf-deep` at **4.06:1** on its
  own tint. Giving those families a derived `-ink` member and migrating the call sites
  is its own change.

## Alternatives considered

**Add `cream-deep` to the list.** The fix for the symptom, and the third time it
would have been applied to this same defect. It leaves the next unlisted ground
exactly as invisible.

**Derive "paper" from luminance** — treat any background dark text reads on as
paper. Fully derived and needs no registry, but it sweeps in `horizon` and
`rarity-legendary`, which would demand every rarity ink clear 4.5:1 on a gold CTA.
Rejected as unsatisfiable.

**Infer the pairing from the markup** — treat a background with no `text-*` class in
the same `className` as inheriting the ambient ink. Genuinely derived, and wrong: the
mobile nav paints `bg-sky` with no text class of its own and its children set
`text-cream`, so indigo would have been classified as paper.

**Model nested tints conservatively**, by composing a family's heaviest tint over
itself. Bounded and derivable, but the choice of *how many* levels is arbitrary, and
at two levels it over-darkened the gold beyond what any rendered page needs.
