# Design tokens

Single source of truth for the **Threadwork** design system. All values are
defined in the `@theme` block of [resources/css/app.css](../resources/css/app.css);
this file is the human-readable index. When you change a token, update it in
`app.css` (the real source) and reflect it here. CLAUDE.md and the README point
here instead of re-describing the palette so they do not drift again.

Tailwind v4 auto-generates utilities from each `--color-*` / `--text-*` token
(e.g. `--color-horizon` → `bg-horizon` / `text-horizon`). **Do not use raw
Tailwind colors** (`gray-*`, `slate-*`, `lime-*`, …); use the semantic tokens.

## Fonts

Three families for the app, plus one card-scoped face:

| Token | Family | Use |
|---|---|---|
| `font-display` | Fraunces (italic) | Display headlines, Temari voice / quotes |
| `font-sans` | Plus Jakarta Sans | Prose + UI (the readable **default** family) |
| `font-mono` | JetBrains Mono | **Telemetry only** — numbers, stats, splits, uppercase metadata labels |
| `font-collectible` | Oswald (condensed) | **Kartu only** — the TCG nameplate, hero KM number, edition number. Not a global default. |

`font-sans` is Tailwind's default family, so prose / UI resolve to Plus Jakarta Sans
automatically — readable Indonesian body text needs no extra class. **Telemetry must carry
an explicit `font-mono`** (numbers/stats via `.text-stat`, uppercase labels via
`.text-label-micro` / `.text-label-small`). Keep `tabular-nums` on any numeric / stat
display. Display headlines + the Temari voice carry `font-display` + `italic` (or the
`.voice` utility). Rule of thumb: **mono = numbers/labels · sans = prose · serif italic
= display/voice**.

Loaded via Google Fonts `<link>` in [app.blade.php](../resources/views/app.blade.php).

## Type scale

Fluid `clamp()` tokens; each bundles line-height + letter-spacing, so one
utility class lands the full spec.

- **Display** (`text-display-xs` … `text-display-2xl`) — editorial / hero headlines.
- **Headline** (`text-headline-xs` … `text-headline-lg`) — section-level headings.
- **Quote** (`text-quote-sm` / `-md` / `-lg`) — fixed px; body reading should not scale with viewport.
- **Stat** (`text-stat`, 32px) — the big tabular number on KPI tiles / PR cards.

The display tier is tuned for **Fraunces** (softer/wider/optical than the old Instrument
Serif), with `font-optical-sizing: auto` handling the `opsz` axis per size. Role → class
mapping is encoded in the role utilities below (`.text-prose`, `.text-stat`, `.text-meta`,
`.voice`) so call sites name a role instead of hardcoding font+size+color.

## Colors

| Family | Tokens | Role |
|---|---|---|
| Sky | `sky`, `sky-deep`, `sky-2` (`#241c54`) | Structure, dark hero panels (only "dark" surface). Deep indigo thread. |
| Horizon | `horizon`, `horizon-deep` (`#d9a53c` gold) | Primary CTA, "earned" / PR state, Temari accent. Gold thread. |
| Cream | `cream`, `cream-deep` (`#f5f0e4`) | Paper / secondary surface, borders, on-dark text. Warm linen canvas. |
| Ink | `ink` / `ink-2` / `ink-3` (+ `ink-on-sky`) | 3-tier text contrast (primary / supporting / meta); `ink-on-sky` = muted label on dark sky |
| Surface | `surface`, `surface-card`, `surface-elev`, `surface-warm`, `surface-sunken`, `line` | App surfaces (dawn-shift drifts `surface`); `surface-card` = the single warm linen all cards share (one token retints every card); `surface-elev` = floating UI only |
| Mood | `mood-{blazing,easy,wobbly,gassed,overloaded,chill}` (+ `-bg`) | Calendar cells, mood badges. Each remapped to a thread jewel tone (gold/emerald/crimson/indigo/violet). |
| Rarity | `rarity-{common,uncommon,rare,epic,legendary}` | Card rarity |
| Hues | `leaf` / `leaf-deep`, `ember` / `ember-deep`, `citrus` / `citrus-deep`, `stone` | Semantic accents; `citrus` reserved for PR / legendaris |
| Strava | `strava-orange`, `strava-orange-hover` | Brand mark only — never themed or restyled |

### Text contrast tiers

- `text-ink` — primary text (body, headings, button labels, KPI values).
- `text-ink-2` — supporting body (subtitles, descriptive lines).
- `text-ink-3` — labels / timestamps / footnotes / metadata only; never body prose. Darkened to `#6e6452` to clear WCAG AA (≥4.5:1) on cream.
- `text-ink-on-sky` (`#b0a3c9`) — muted metadata label on dark sky panels. Replaces the old `text-cream/55`, which failed AA (~2.2:1).

### CTA contrast

- `horizon` (gold) → dark text (`text-sky` / `text-ink`), never white.
- `sky` / `sky-deep` (deep indigo) → `text-cream` / white.
- `leaf-deep` → `text-cream` (passes AA); darken on hover with `hover:opacity-90`, not a hue jump.

## Gradients & atmospherics

- `<GradientText preset="horizon|cream-sun" fontSize=… />` ([component](../resources/js/components/ui/GradientText.tsx)) clips a `linear-gradient` to text. Numbers only, large sizes only, one per viewport.

## Spacing rhythm

- Major section → next major: `mt-10`
- Subsection → next: `mt-6`
- `<h2>` → content: `mt-3`
- Page header → first section: `mt-8`
- Hero card padding `p-6`; data card `p-4`; chip/pill `px-3 py-1`

App is **light-mode only** — no `.dark` is applied to `<html>`, no `*-dark` tokens.

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
   [AksesoriUnlockModal](../resources/js/components/celebrations/AksesoriUnlockModal.tsx),
   [UnlockToast](../resources/js/components/temari/UnlockToast.tsx)) and the mascot's
   `idleByMood` / fidget keyframes in `lib/motion.ts`. Reserved for moments that are actually
   earned — never layer tier 3 onto routine navigation or data loading.

## Elevation

No new shadow tokens — Tailwind's default `shadow-*` scale, used at three fixed steps mapped to
how far a surface floats above `surface` / `surface-card`:

| Step | Class | Surface | Use |
|---|---|---|---|
| Resting | `shadow-sm` | `surface-card` / `surface-warm` | Cards in the normal document flow (list rows, summary cards) |
| Floating | `shadow-lg` | `surface-elev` | Popovers, dropdown menus, toasts, tooltips — overlays content without being modal |
| Overlay | `shadow-2xl` | `cream` / `sky-deep` | Full modals and takeover sheets (share card, unlock modal, kartu mount) |

`surface-elev` is reserved for the floating step — never use it for a resting card (that's
`surface-card` / `surface-warm`). Pair each shadow step with its matching surface token rather
than mixing tiers (a `shadow-2xl` modal on a resting `surface-warm` background reads as
inconsistent depth).

## Component utilities

Reusable atomic classes in the `@layer components` block of [app.css](../resources/css/app.css),
built with `@apply` so they compose with token utilities. Prefer these over re-typing the combo.

| Class | Expands to | Use |
|---|---|---|
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

**Card-scoped exception:** the collectible **Kartu** (and `KartuMini` / its on-card `ZoneBar` legend) is a deliberate TCG artifact rendered in `font-collectible` (Oswald), where a sub-11px nameplate, `km` unit, edition number, and zone legend are part of the trading-card look, not app chrome. Sub-11px is allowed **only inside the card art**; everywhere else the 11px floor holds.

## Variant maps (cva)

Component style variants live in [resources/js/lib/variants.ts](../resources/js/lib/variants.ts)
as [class-variance-authority](https://cva.style) definitions: `cardVariants`, `pillButtonVariants`,
`chipVariants`, `toggleButtonVariants` (segmented / filter controls), `iconButtonVariants`
(bare-icon buttons), `rarityVariants`. Consume them with the `cn()` merge helper:

```tsx
import { cardVariants } from '@/lib/variants';
import { cn } from '@/lib/cn';

// before — inline Record-table lookups inside the component
const TONE_CLASS = { cream: 'rounded-2xl bg-cream', /* … */ } as const;
className={cn(TONE_CLASS[tone], PADDING_CLASS[padding], className)}

// after — cva owns the variant matrix; cn() merges caller overrides
className={cn(cardVariants({ tone, padding }), className)}
```

cva keeps `variant → class` matrices (tone / size / boolean compounds) declarative and typed.
Data maps that are *not* style-variant matrices — [lib/mood.ts](../resources/js/lib/mood.ts) (mood →
face / label / fill) and [lib/tones.ts](../resources/js/lib/tones.ts) (icon-tile tones) — stay as
plain `Record` lookups; do **not** fold those into cva. The remaining ~16 Record-table components
can be migrated to this pattern incrementally; `Card` / `PillButton` / `Chip` are the pilots.

## Common pitfalls

- **Raw Tailwind colors slip in.** A quick `bg-gray-100` or `text-lime-600` reads as laziness or an incomplete refactor. Every utility must resolve to a semantic `--color-*` token. Enforced in CI by [scripts/check-raw-palette.mjs](../scripts/check-raw-palette.mjs) (`npm run check:palette`), which fails on any raw Tailwind palette family + numeric shade combo in `resources/js`.
- **`text-ink-3` on body prose.** `ink-3` is for labels/timestamps/metadata only, never wrapping a `<p>` of running text. Sweep `grep text-ink-3` before merging — if any match wraps a prose paragraph, it's wrong.
- **Missing `tabular-nums` on stat displays.** Any big-number display (`<span class="text-stat">`, KPI tiles, PR times) must carry `tabular-nums` so digits don't jitter as they change. The `.text-stat` / `.text-stat-sm` utilities include it; raw `font-mono` alone does not.
- **`font-mono` omitted from uppercase labels.** Because `font-sans` is Tailwind's default, every `.text-label-micro` / `.text-label-small` utility needs an explicit `font-mono` — without it the label renders in the body font. Prefer the utility class over hand-rolling the combo.
- **Gradient text on non-numeric content.** `<GradientText>` is for numbers only (display-sized stats, KPI values), never for headlines or body prose. Scarcity makes it premium; using it on a heading feels like Las Vegas.
