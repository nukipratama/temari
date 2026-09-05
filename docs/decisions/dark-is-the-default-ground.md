---
title: Dark is the default ground, and both grounds are authored
description: The app ships two grounds switched by a data-theme attribute on <html>, with dark as the default; a ground-reactive semantic layer sits above a fixed named palette, and the -ink tier inverts rather than being redefined per call site.
tags: [decision, design]
status: accepted
reviewed: 2026-09-02
code_refs:
  - resources/css/app.css
  - resources/views/app.blade.php
  - resources/js/hooks/useSystemTheme.ts
  - resources/js/components/settings/AppearanceCard.tsx
  - resources/brand/grounds.mjs
  - resources/brand/grounds.json
  - tests/Unit/Architecture/DesignTokenContrastTest.php
---

# Dark is the default ground, and both grounds are authored

**Status:** Accepted (2026-09-02, implemented in `F2`)

> **One claim below is superseded (noted 2026-09-06) by [[system-is-the-default-ground]].** Dark is no longer the ground an unconfigured visitor gets: with no explicit `light`/`dark` stored, the pre-paint script, the hook and the CSS fallback all resolve from `prefers-color-scheme` instead. Everything else here — two authored grounds, the `data-theme` switch, the three-layer token model, the `-ink` tier inversion and the both-grounds audit — still stands exactly as written.

## Context

Every version of this app before the mobile-UX port was light-only, and said so as a design
principle: cold near-white paper, near-black structure, a lime accent. The palette encoded that
assumption in a way a CSS class could not undo.

The clearest instance is the **`-ink` tier**. `--color-leaf-ink`, `--color-ember-ink`,
`--color-citrus-ink` and the ten `--color-rarity-*-ink` exist because a vivid fill is unreadable as
*text on paper* — so each family ships a fill for dots, frames and tints, and a darkened `-ink`
sibling that is the only member allowed to carry a label. On a dark ground the relationship
inverts: the vivid fill is what reads, and the darkened `-ink` is what does not. A single fixed
value per token cannot serve both, and there are ~96 call sites.

The prototype answered the surface question — it draws a dark app — but not this one. Its own
`@theme inline` block keeps the entire named palette and adds a **semantic indirection layer**
above it (`background`, `foreground`, `card`, `popover`, `muted`, `accent`, plus hand-built
`icon-accent` / `text-2` / `text-3` / `border-strong`). That layer is the mechanism: a value that
must change with the ground is addressed by role, and a value that is fixed identity is addressed
by name.

## Decision

**Author both grounds, ship dark as the default, and switch on `data-theme` on `<html>` — never a
`.dark` class.**

Three layers, and which one a call site uses is the whole convention:

1. **The named palette** — literal hex, fixed identity, the same on both grounds. `--sky`,
   `--cream`, `--horizon`, the eighteen `--mood-*`, the ten `--rarity-*`, `--strava-orange`.
2. **Ground-reactive slots** — redefined under [`html[data-theme='dark']`](resources/css/app.css#L404).
   On the dark ground the Sky family becomes ground and Cream becomes text, the exact inverse of
   the light one.
3. **`@theme inline`** — maps both to Tailwind utilities.

**The `-ink` tier joins layer 2 rather than gaining a parallel `-on-dark` set.** The class name
stays `text-leaf-ink` at every call site and its *value* flips, so the ~96 sites needed no edit and
a new one cannot pick the wrong variant. [`darkGrounds()`](resources/brand/grounds.mjs#L163)
derives the dark half beside the existing `paperGrounds()`, so both come from one source.

**Persistence is `localStorage` plus a blocking inline script**, not a database column or a shared
prop. [app.blade.php](resources/views/app.blade.php#L29) stamps `data-theme` before any other
resource in `<head>`, so there is no flash, and a guest on Login gets their ground without a
session. `system` resolves through `prefers-color-scheme` with a live listener in
[`useSystemTheme`](resources/js/hooks/useSystemTheme.ts#L13); the control is the Light / Dark /
System group in [AppearanceCard](resources/js/components/settings/AppearanceCard.tsx#L25).

**Both grounds are scored, not just the default.** `DesignTokenContrastTest` audits the dark block
as well as the paper grounds, and [grounds.json](resources/brand/grounds.json) still fails closed
in both directions — a background nothing classifies fails the build, and so does one classified
but never painted. See [[ink-grounds-derived-not-listed]] for why that registry is derived from the
render rather than hand-listed.

## Consequences

- **Enables:** one class name that is correct on both grounds, so a component author never asks
  which ground they are on. The audit answers contrast for the ground a token is actually used on.
- **Costs:** every new colour decision now has two answers, and the wrong layer is a silent bug
  rather than a failing test — a fixed value used where the ground moves reads wrong on one ground,
  and a semantic value used where identity is fixed drifts a brand colour.
- **`text-foreground` is wrong on a lime CTA.** `foreground` flips to cream on dark, which is the
  unreadable pairing on `horizon`, so that one preset pins the fixed `text-sky` deliberately. This
  is the sharpest example of the cost above, and both [[design-tokens]] and the `temari` skill had
  documented the semantic value there until `W4` corrected them.
- **Dawn-shift did not survive.** When this decision was taken it was expected to live on as a
  light-ground-only drift of the surface tints. `PP3` cut it instead — the prototype draws no such
  drift — and `W2` removed the reader, so the grounds registry no longer reads
  `body[data-time-of-day]` at all.
- **Fixed-dark surfaces must pin their own text.** A card that is deliberately dark on both grounds
  (the sky panels) cannot inherit `foreground`, or it turns unreadable on the light ground.

## See also

- [[design-tokens]] — the full table, and which layer each family belongs to.
- [[ink-grounds-derived-not-listed]] — how the grounds an `-ink` token is scored against are derived.
- [[frontend-architecture]] — where the pre-paint script sits in the request lifecycle.
