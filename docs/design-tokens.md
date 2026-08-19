# Design tokens

Single source of truth for the design system. All values are defined in the
`@theme` block of [resources/css/app.css](../resources/css/app.css); this file is the
human-readable index. The block is **generated**, by
[resources/brand/build-tokens.mjs](../resources/brand/build-tokens.mjs) — that script owns
the rules (the fill/text split, the outline rule, the derived `-ink` values), so a token is
changed there and re-emitted, never hand-tuned in `app.css`. When a token moves, reflect it
here. CLAUDE.md and the README point here instead of re-describing the palette so they do not
drift again.

> The palette carried the codename **Threadwork** through v1, borrowed from an
> embroidery metaphor the product no longer tells. The name is retired: this is
> just the design system now. (Unrelated: the AI layer's *mood vocabulary* —
> `blazing`, `easy`, `gassed` … — is a separate list, documented in
> [[voice-and-tone]].)

Tailwind v4 auto-generates utilities from each `--color-*` / `--text-*` / `--radius-*` /
`--shadow-*` / `--spacing-*` token (e.g. `--color-horizon` → `bg-horizon`, `--shadow-e1` →
`shadow-e1`). The block is declared `@theme static`, so every token is emitted to `:root`
whether or not a utility references it — `/devtools/design` reads them back out of the live
stylesheet, and a pruned variable would read there as a missing token.

**Two guards enforce this page.** [scripts/check-raw-palette.mjs](../scripts/check-raw-palette.mjs)
(`npm run check:palette`) fails CI on a value that never reached a token — a raw Tailwind
shade, a default `shadow-lg`, an off-scale `rounded-2xl`. `/devtools/design`
([Design.tsx](../resources/js/pages/Devtools/Design.tsx)) catches the other half: values that
*are* tokenised but inconsistent, by rendering the audits against the live CSS.

## Fonts

Three families, one job each:

| Token | Family | Use |
|---|---|---|
| `font-display` | Fraunces (italic) | Display headlines, Temari voice / quotes |
| `font-sans` | Plus Jakarta Sans | Prose + UI (the readable **default** family) |
| `font-mono` | JetBrains Mono | **Telemetry only** — numbers, stats, splits, uppercase metadata labels |

There used to be a fourth, Oswald, scoped to the collectible Kartu. It was the single biggest
reason the collection read as a different product from the rest of the app, so it is retired
and the card chrome uses the same sans/mono stack (including the share-card canvas in
[shareCard.ts](../resources/js/lib/shareCard.ts)).

`font-sans` is Tailwind's default family, so prose / UI resolve to Plus Jakarta Sans
automatically. **Telemetry must carry an explicit `font-mono`** (numbers/stats via `.text-stat`,
uppercase labels via `.text-label-micro` / `.text-label-small`). Keep `tabular-nums` on any
numeric / stat display. Rule of thumb: **mono = numbers/labels · sans = prose · serif italic =
display/voice**.

**Exemption — the Kartu art layer.** The collectible card's rarity label, TRIMP number and
edition number are **sans**, and stay that way. The card is art, not UI chrome: its type is
composed into the illustration rather than tokenised, and the two surfaces that draw a full-size
Kartu — the DOM [Kartu.tsx](../resources/js/components/card/Kartu.tsx#L328) and the `kartu` layout
of the canvas share renderer [shareCard.ts](../resources/js/lib/shareCard.ts#L816) — draw all three
identically, so "fixing" one desyncs a pair that was converged on purpose. The boundary is exactly
that art layer: [KartuMini.tsx](../resources/js/components/card/KartuMini.tsx#L111), the `rute` /
`stats` share layouts, and the server story card
[RunCardImageRenderer.php](../app/Services/Run/Story/RunCardImageRenderer.php#L204) all stay mono,
and the mono-for-numbers-and-uppercase-metadata rule is absolute everywhere else in the app.

Loaded via Google Fonts `<link>` in [app.blade.php](../resources/views/app.blade.php).

## Type scale

Fluid `clamp()` tokens; each bundles line-height + letter-spacing, so one
utility class lands the full spec.

- **Display** (`text-display-xs` … `text-display-2xl`) — editorial / hero headlines.
- **Headline** (`text-headline-xs` … `text-headline-lg`) — section-level headings.
- **Quote** (`text-quote-sm` / `-md` / `-lg`) — fixed px; body reading should not scale with viewport.
- **Stat** (`text-stat`, 32px) — the big tabular number on KPI tiles / PR cards.

The display tier is tuned for **Fraunces**, with `font-optical-sizing: auto` handling the `opsz`
axis per size. Role → class mapping is encoded in the role utilities below (`.text-prose`,
`.text-stat`, `.text-meta`, `.voice`) so call sites name a role instead of hardcoding.

## Colors

### Fill vs text — the split every saturated family carries

No saturated colour can be both a fill and a label on cream and still pass contrast. So each
family ships as a **pair**: the vivid value is the **fill** (dots, frames, strokes, tinted
cells), and a derived `-ink` value — the same hue darkened until it clears **4.5:1 on paper** —
is the only member allowed to carry **text or an icon**. `text-rarity-legendary` is therefore
always wrong; it is `text-rarity-legendary-ink`. This is the single most common way the palette
gets misused, and it is what the audit on `/devtools/design` exists to catch.

**Paper is not one colour, and the list of them is never written down.** Every derived value
targets the **darkest** ground the app can render, and every audit scores a paper pair against
all of them and reports its **worst**. What counts as "all of them" is *derived*, by
[grounds.mjs](../resources/brand/grounds.mjs): token values come out of the shipped `@theme`
block and the `body[data-time-of-day]` rules ([app.css](../resources/css/app.css):555), and the
set of backgrounds in play comes out of every `bg-*` utility `resources/js` actually paints.
[grounds.json](../resources/brand/grounds.json) records only what *kind* each background is, and
one it does not classify **fails the build** rather than being skipped.

An `-ink` token is scored on three things: every `paper` ground, its own `-bg` cell when it
paints one, and its heaviest `bg-<family>/<alpha>` tint composited over the darkest paper — a
chip prints on the tint, not on the paper under it. All three audits read that same registry:
the generator's ([build-tokens.mjs](../resources/brand/build-tokens.mjs):177), the client-side one
behind `/devtools/design` ([designTokens.ts](../resources/js/lib/designTokens.ts):377), and the CI
guard ([DesignTokenContrastTest.php](../tests/Unit/Architecture/DesignTokenContrastTest.php)).

Hand-listing the grounds instead is what shipped the whole hue-derived ink tier at ~4.3:1 on
`cream-deep` — the ground `AppShell` paints under the entire app — while all three audits
reported a pass. See [[ink-grounds-derived-not-listed]].

**The outline rule.** Two fills (legendary gold, uncommon green) cannot reach even 3:1 on paper
without losing the vibrancy that makes a legendary pull feel legendary. WCAG 1.4.11 is satisfied
by an object's *edge*, so those keep their fill and are drawn with a **2px `-ink` outline** — the
edge carries the contrast. Never darken them instead.

| Family | Tokens | Role |
|---|---|---|
| Sky | `sky`, `sky-deep`, `sky-2` (`#241c54`) | Structure, dark hero panels (the only "dark" surface). Deep indigo. |
| Horizon | `horizon`, `horizon-deep`, `horizon-ink` (`#d9a53c` gold) | Primary CTA, "earned" / PR state, Temari accent. `horizon-ink` for gold **as text**; `horizon-deep` is a *fill* (hover state, gradient stop, chart point) and reaches only 2.6:1 on paper, so `text-horizon-deep` is always wrong. |
| Cream | `cream`, `cream-deep` (`#f5f0e4`) | Paper / secondary surface, on-dark text. Warm linen canvas. |
| Ink | `ink` / `ink-2` / `ink-3` (+ `ink-on-sky`, `ink-on-rarity`) | 3-tier text contrast (primary / supporting / meta); `ink-on-sky` = muted label on a dark sky panel, `ink-on-rarity` = label on a vivid rarity fill |
| Surface | `surface`, `surface-card`, `surface-elev`, `surface-warm`, `surface-sunken` | App surfaces (dawn-shift drifts `surface`); `surface-card` = the one linen every card shares; `surface-elev` = floating UI only |
| Line | `line`, `line-strong` | Borders. `line` is the default hairline; `line-strong` is the dashed placeholder edge |
| Mood | `mood-{blazing,easy,wobbly,gassed,overloaded,chill}` (+ `-bg`, `-ink`) | Calendar cells, mood badges. `-bg` is the pastel cell tint, `-ink` the label |
| Rarity | `rarity-{common,uncommon,rare,epic,legendary}` (+ `-ink`) | Card rarity. Loud on purpose: it is the collectible signal |
| Hues | `leaf` / `leaf-deep` / `leaf-ink`, `ember` / `ember-deep` / `ember-ink`, `citrus` / `citrus-ink`, `stone` | Semantic accents; `citrus` reserved for PR / legendary celebration. `-ink` carries the label, `-deep` fills a dark CTA under `text-cream` and is never text. `citrus` has no `-deep`: it fills no CTA |
| Strava | `strava-orange`, `strava-orange-hover` | Brand mark only — never themed or restyled |

Chart.js and inline SVG cannot read CSS custom properties off a canvas, so a small
hex bridge mirrors the tokens in [chartTokens.ts](../resources/js/lib/chartTokens.ts). Import
from there rather than pasting a hex; it is asserted against `app.css` by its own test.

### Text contrast tiers

- `text-ink` — primary text (body, headings, button labels, KPI values).
- `text-ink-2` — supporting body (subtitles, descriptive lines).
- `text-ink-3` — labels / timestamps / footnotes / metadata only; never body prose.
- `text-ink-on-sky` — muted metadata label on dark sky panels.
- `text-ink-on-rarity` — label printed on a vivid rarity fill (the rarity flag).

All five clear WCAG AA on their intended background.

### CTA contrast

- `horizon` (gold) → dark text (`text-sky` / `text-ink`), never white.
- `sky` / `sky-deep` (deep indigo) → `text-cream` / white.
- `leaf-deep` / `ember-deep` → `text-cream` (both pass AA); darken on hover with `hover:opacity-90`, not a hue jump.

## Spacing

A 4px base scale (`--spacing-1` … `--spacing-16`, i.e. `p-4` = 16px), plus **named padding
roles** so a component asks for its *kind* of padding instead of picking a number. Before the
roles existed there were 23 distinct padding values across the brand previews.

| Role | Value | Utility | Use |
|---|---|---|---|
| `--pad-chip` | `4px 12px` | `.pad-chip` | Chips, pills, small badges |
| `--pad-panel` | `12px 16px` | `.pad-panel` | Dense panels, list rows, compact cards |
| `--pad-card` | `16px` | `.pad-card` | The default card |
| `--pad-hero` | `24px` | `.pad-hero` | Hero cards, big sections |
| `--pad-page` | `48px 40px 80px` | `.pad-page` | Page gutter |

Padding is not a Tailwind theme namespace, so the roles ship as `.pad-*` classes in the
`@layer components` block of `app.css`. They sit in the components layer, so a call-site `p-*`
utility still wins when a one-off is genuinely needed.

Section rhythm (unchanged): major section → next major `mt-10`; subsection → next `mt-6`;
`<h2>` → content `mt-3`; page header → first section `mt-8`.

## Radius

| Token | Value | Use |
|---|---|---|
| `rounded-xs` | 6px | Bars, progress fills, tiny inner tiles |
| `rounded-sm` | 10px | Inputs, small tiles, swatches |
| `rounded-md` | **14px** | **The card / panel corner** |
| `rounded-lg` | 18px | Larger panels, modals, sheets |
| `rounded-xl` | 24px | Takeover surfaces, bottom sheets |
| `rounded-full` | 9999px | Pills, chips, avatars, dots |

These **override Tailwind's defaults for the whole namespace**, so no call site can land between
two steps. `rounded-2xl` / `rounded-3xl` are outside the scale and rejected by the source guard —
that is how one screen ended up with four different card corners. Arbitrary radii
(`rounded-[11px]`) survive only inside the collectible card art, which is drawn to its own
geometry.

## Elevation

Four warm-tinted steps. Warm, not neutral: a grey shadow on a cream ground reads dirty.

| Step | Token | Surface | Use |
|---|---|---|---|
| Resting | `shadow-e1` | `surface-card` / `surface-warm` | Cards in the normal document flow |
| Floating | `shadow-e2` | `surface-elev` | Popovers, dropdowns, toasts, tooltips |
| Sheet | `shadow-e3` | `surface-elev` / `cream` | Bottom sheets, large detached panels |
| Modal | `shadow-e4` | `cream` / `sky-deep` | Full modals and takeovers (share card, unlock, kartu mount) |

Tailwind's default `shadow-*` scale is not used and is rejected by the source guard.
`surface-elev` is reserved for the floating step — never a resting card (that is `surface-card` /
`surface-warm`). Pair each step with its matching surface rather than mixing tiers.

The resting step ships inside `cardVariants` ([variants.ts](../resources/js/lib/variants.ts)), so
`Card` and `LinkCard` carry it already — do not re-apply `shadow-e1` at a call site. Elevation
applies once per stack: a tile nested inside a card ([StatTile](../resources/js/components/ui/StatTile.tsx))
stays flat, because the card it sits in already carries the resting step.

## Motion

Three tiers, built from `framer-motion` variants in
[lib/motion.ts](../resources/js/lib/motion.ts) (declarative-only: `Variants` / `Transition`
constants, no functions or branches) plus the `.pressable` CSS primitive below. Every tier sits
inside the app-wide `<MotionConfig reducedMotion="user">`
([AppShell.tsx](../resources/js/layouts/AppShell.tsx)): a transform property (scale, x/y, rotate)
reduces to an instant snap under the user's OS reduced-motion setting, while `opacity` keeps
animating — the one cue a reduced-motion user still gets.
`MotionConfig` only reaches motion *components*, so anything animating imperatively — the
stat count-ups ([useCountUp.ts](../resources/js/hooks/useCountUp.ts)), the SVG glyph draw-ins,
the confetti burst — reads the same preference itself through
[useReducedMotion](../resources/js/hooks/useReducedMotion.ts) and snaps to its end state.

1. **Global / subtle** — press feedback and route transitions; present everywhere, never opt-in.
   `pressShrink` (scale 0.97 + 70% opacity dip, 150ms) is the one convention both
   [MotionLink](../resources/js/components/MotionLink.tsx) (default `whileTap`) and `.pressable`
   (its CSS `active:` state) implement, so a framer-driven link and a plain button feel identical.
   `routeProgressBar` drives [RouteProgressBar](../resources/js/components/RouteProgressBar.tsx),
   a thin top bar mounted as a **sibling** of AppShell's `<main>`, never a wrapper around it — that
   element is deliberately unkeyed (keying it once caused 25 card remounts on Collection). It's
   gated on Inertia's own `visit.showProgress` flag, so background/partial reloads (AI-analysis
   polling, card-reveal `only` refreshes) never light the bar.
2. **Data reveal** — a page's first showing of real data, not every render. Stat count-ups
   (`useCountUp` + `countUpEase`, an ease-out curve with no overshoot — a tallying number should
   land exactly on target), chart/route draw-ins (`drawIn`, SVG `pathLength` 0→1), and staggered
   group reveals (`staggerContainer` wrapping `fadeInUp` children).
3. **Celebratory** — unlocks, PRs, streaks: the existing celebration overlays
   ([CardReveal](../resources/js/components/card/CardReveal.tsx),
   [AccessoryUnlockModal](../resources/js/components/celebrations/AccessoryUnlockModal.tsx),
   [UnlockToast](../resources/js/components/temari/UnlockToast.tsx)) and the mascot's
   `idleByMood` / fidget keyframes in `lib/motion.ts`. Reserved for moments that are actually
   earned — never layer tier 3 onto routine navigation or data loading.

## Gradients & atmospherics

- `<GradientText preset="horizon|cream-sun" fontSize=… />` ([component](../resources/js/components/ui/GradientText.tsx)) clips a `linear-gradient` to text. Numbers only, large sizes only, one per viewport.

App is **light-mode only** — no `.dark` is applied to `<html>`, no `*-dark` tokens.

## Component utilities

Reusable atomic classes in the `@layer components` block of [app.css](../resources/css/app.css),
built with `@apply` so they compose with token utilities. Prefer these over re-typing the combo.

| Class | Expands to | Use |
|---|---|---|
| `.pad-chip` / `.pad-panel` / `.pad-card` / `.pad-hero` / `.pad-page` | the matching `--pad-*` role | Named padding (see Spacing) |
| `.focus-ring` | `focus-visible:ring-2 ring-leaf ring-offset-2 ring-offset-cream` (+ `outline-none`) | Keyboard focus on cream surfaces (the app default) |
| `.focus-ring-on-sky` | same, but `ring-offset-sky` | Keyboard focus on dark sky panels |
| `.text-label-micro` | `font-mono text-[11px] font-bold uppercase tracking-[0.12em]` | Smallest uppercase metadata label (kartu / stat captions) |
| `.text-label-small` | `font-mono text-[12px] font-bold uppercase tracking-[0.14em]` | Section labels, chip-sized uppercase metadata |
| `.text-label-hero` | `font-mono text-[11px] font-bold uppercase tracking-[0.18em]` | Page-hero eyebrow (wide tracking) |
| `.text-prose` | `font-sans text-quote-md text-ink-2` | Narrator / body sentences |
| `.text-stat` | `font-mono text-stat font-bold tabular-nums text-ink` | The big tabular KPI / PR number |
| `.text-stat-sm` | `font-mono text-2xl font-bold tabular-nums text-ink` | Smaller stat figure (compact tiles) |
| `.text-meta` | `font-mono text-[11px] tracking-[0.04em] text-ink-3` | Date / timestamp / footnote (non-uppercase metadata) |
| `.voice` | `font-display text-quote-lg italic text-ink` | Temari voice (display serif italic) |

Text floor is **11px** in app chrome — no `text-[9px]` / `text-[10px]`. Prefer a role utility over a raw size.

**Card-scoped exception:** the collectible **Kartu** (and `KartuMini` / its on-card `ZoneBar`
legend) is a deliberate TCG artifact, where a sub-11px nameplate, `km` unit, edition number and
zone legend are part of the trading-card look, not app chrome. Sub-11px is allowed **only inside
the card art**; everywhere else the 11px floor holds.

## Variant maps (cva)

Component style variants live in [resources/js/lib/variants.ts](../resources/js/lib/variants.ts)
as [class-variance-authority](https://cva.style) definitions: `cardVariants`, `pillButtonVariants`,
`chipVariants`, `toggleButtonVariants` (segmented / filter controls), `iconButtonVariants`
(bare-icon buttons), `rarityVariants`. Consume them with the `cn()` merge helper:

```tsx
import { cardVariants } from '@/lib/variants';
import { cn } from '@/lib/cn';

className={cn(cardVariants({ tone, padding }), className)}
```

**There is one card.** `cardVariants` is a single surface — `surface-card` on a `line` border at
`rounded-md` with `shadow-e1` — in four states, not a spread of competing treatments:

| Tone | What it is |
|---|---|
| `card` | The card. Every resting surface in the app. |
| `sky` | The card inverted into the dark panel itself: `bg-sky` under `text-cream`, lifted to `shadow-e2`. |
| `onSky` | The same card mounted *on* a dark sky panel: translucent cream over the panel, no elevation (there is nothing to cast onto). |
| `empty` | The dashed placeholder standing in for a card with no content yet. Not a surface that rests on the page, so no elevation. |

Padding names its role (`panel` / `card` / `hero` / `none`), never a number. A tone or padding
that "just needs to be a bit different" at one call site is the drift this collapse removed —
override with `className` if a one-off is genuinely required, so it stays visible in review.

Data maps that are *not* style-variant matrices — [lib/mood.ts](../resources/js/lib/mood.ts) (mood →
face / label / fill) and [lib/tones.ts](../resources/js/lib/tones.ts) (icon-tile tones) — stay as
plain `Record` lookups; do **not** fold those into cva.

## Common pitfalls

- **Using a fill colour as a label.** `text-mood-blazing` / `text-rarity-legendary` / `text-horizon-deep` / `text-leaf-deep` / `text-ember-deep` fail contrast on paper. Reach for the `-ink` member. This is exactly the bug the fill/text split exists to prevent, and it happened once inside the generator itself.
- **Reading `-deep` as "the dark one".** `-deep` is a *fill* for a dark CTA, sized to carry `text-cream`; it is never a label colour. The semantic accents had no `-ink` member until the tier gained one, which is how ~85 call sites reached for `-deep` and how `citrus-deep` shipped at **2.96:1** on the page ground and **2.68:1** on its own `bg-citrus/15` chip. On a *dark* ground the vivid fill is still the right label colour (`text-leaf` on a sky panel), not the ink.
- **Checking contrast against a ground list you wrote by hand.** Paper drifts with dawn-shift, the app's own page ground is `cream-deep`, a mood chip sits on its `-bg` cell, and a tinted chip sits on the tint rather than the paper. Score against every ground the render produces and take the worst; if you are adding a background, classify it in [grounds.json](../resources/brand/grounds.json) — the build fails until you do.
- **Darkening a light fill instead of outlining it.** Legendary gold and uncommon green stay vivid and take a 2px `-ink` outline; the edge carries the contrast.
- **Raw Tailwind colors, default shadows, off-scale radii.** Every utility must resolve to a token. Enforced in CI by [scripts/check-raw-palette.mjs](../scripts/check-raw-palette.mjs).
- **`text-ink-3` on body prose.** `ink-3` is for labels/timestamps/metadata only, never wrapping a `<p>` of running text. Sweep `grep text-ink-3` before merging.
- **Missing `tabular-nums` on stat displays.** Any big-number display must carry it so digits don't jitter as they change. The `.text-stat` / `.text-stat-sm` utilities include it; raw `font-mono` alone does not.
- **`font-mono` omitted from uppercase labels.** Because `font-sans` is Tailwind's default, every `.text-label-micro` / `.text-label-small` utility needs an explicit `font-mono` — without it the label renders in the body font.
- **Gradient text on non-numeric content.** `<GradientText>` is for numbers only (display-sized stats, KPI values), never for headlines or body prose.
