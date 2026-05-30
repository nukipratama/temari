# Design tokens

Single source of truth for the **Daybreak** design system. All values are
defined in the `@theme` block of [resources/css/app.css](../resources/css/app.css);
this file is the human-readable index. When you change a token, update it in
`app.css` (the real source) and reflect it here. CLAUDE.md and the README point
here instead of re-describing the palette so they do not drift again.

Tailwind v4 auto-generates utilities from each `--color-*` / `--text-*` token
(e.g. `--color-horizon` → `bg-horizon` / `text-horizon`). **Do not use raw
Tailwind colors** (`gray-*`, `slate-*`, `lime-*`, …); use the semantic tokens.

## Fonts

Three families:

| Token | Family | Use |
|---|---|---|
| `font-display` | Instrument Serif (italic) | Headlines, Temari voice / quotes |
| `font-sans` | Inter | Body, UI, numbers, buttons (the default family) |
| `font-mono` | JetBrains Mono | Small uppercase metadata labels only (section labels, chips, stat-tile / kartu captions, timestamps) |

`font-sans` is Tailwind's default family, so body / UI / numbers resolve to Inter
automatically. **Small uppercase metadata labels must carry an explicit `font-mono`**
(or the `.text-label-micro` / `.text-label-small` utilities) — otherwise they fall back
to Inter. Keep `tabular-nums` on any numeric / stat display.

Loaded via Google Fonts `<link>` in [app.blade.php](../resources/views/app.blade.php).

## Type scale

Fluid `clamp()` tokens; each bundles line-height + letter-spacing, so one
utility class lands the full spec.

- **Display** (`text-display-xs` … `text-display-2xl`) — editorial / hero headlines.
- **Headline** (`text-headline-xs` … `text-headline-lg`) — section-level headings.
- **Quote** (`text-quote-sm` / `-md` / `-lg`) — fixed px; body reading should not scale with viewport.
- **Stat** (`text-stat`, 32px) — the big tabular number on KPI tiles / PR cards.

Role → class mapping lives in the CLAUDE.md "Typography & fonts" table.

## Colors

| Family | Tokens | Role |
|---|---|---|
| Sky | `sky`, `sky-deep`, `sky-2` (`#1f2747`) | Structure, dark hero panels (only "dark" surface) |
| Horizon | `horizon`, `horizon-deep` (`#e8a076` peach) | Primary CTA, "earned" / PR state, Temari accent |
| Cream | `cream`, `cream-deep` (`#f6f1e8`) | Paper / secondary surface, borders, on-dark text |
| Ink | `ink` / `ink-2` / `ink-3` | 3-tier text contrast (primary / supporting / meta) |
| Surface | `surface`, `surface-elev`, `surface-warm`, `surface-sunken`, `line` | App surfaces (dawn-shift drifts `surface`) |
| Mood | `mood-{nyala,enteng,oleng,lemes,mumet,adem}` (+ `-bg`) | Calendar cells, mood badges |
| Rarity | `rarity-{common,uncommon,rare,epic,legendary}` | Card rarity |
| Hues | `leaf` / `leaf-deep`, `ember` / `ember-deep`, `citrus` / `citrus-deep`, `stone` | Semantic accents; `citrus` reserved for PR / legendaris |
| Strava | `strava-orange`, `strava-orange-hover` | Brand mark only — never themed or restyled |

### Text contrast tiers

- `text-ink` — primary text (body, headings, button labels, KPI values).
- `text-ink-2` — supporting body (subtitles, descriptive lines).
- `text-ink-3` — labels / timestamps / footnotes / metadata only; never body prose.

### CTA contrast

- `horizon` (light peach) → dark text (`text-sky` / `text-ink`), never white.
- `sky` / `sky-deep` (dark navy) → `text-cream` / white.
- `leaf-deep` → `text-cream` (passes AA); darken on hover with `hover:opacity-90`, not a hue jump.

## Gradients & atmospherics

- `<GradientText preset="horizon|cream-sun" fontSize=… />` ([component](../resources/js/components/ui/GradientText.tsx)) clips a `linear-gradient` to text. Numbers only, large sizes only, one per viewport.
- `<MeshBackdrop variant="dawn|night|ember" />` ([component](../resources/js/components/MeshBackdrop.tsx)) — three blurred radial blobs; `relative overflow-hidden` parent. Mainly the login page.

## Spacing rhythm

- Major section → next major: `mt-10`
- Subsection → next: `mt-6`
- `<h2>` → content: `mt-3`
- Page header → first section: `mt-8`
- Hero card padding `p-6`; data card `p-4`; chip/pill `px-3 py-1`

App is **light-mode only** — no `.dark` is applied to `<html>`, no `*-dark` tokens.

## Component utilities

Reusable atomic classes in the `@layer components` block of [app.css](../resources/css/app.css),
built with `@apply` so they compose with token utilities. Prefer these over re-typing the combo.

| Class | Expands to | Use |
|---|---|---|
| `.focus-ring` | `focus-visible:ring-2 ring-leaf ring-offset-2 ring-offset-cream` (+ `outline-none`) | Keyboard focus on cream surfaces (the app default) |
| `.focus-ring-on-sky` | same, but `ring-offset-sky` | Keyboard focus on dark sky panels |
| `.text-label-micro` | `font-mono text-[10px] uppercase tracking-[0.14em]` | Smallest uppercase metadata label (kartu / stat captions) |
| `.text-label-small` | `font-mono text-[11px] uppercase tracking-[0.16em]` | Section labels, chip-sized uppercase metadata |

## Variant maps (cva)

Component style variants live in [resources/js/lib/variants.ts](../resources/js/lib/variants.ts)
as [class-variance-authority](https://cva.style) definitions: `cardVariants`, `pillButtonVariants`,
`chipVariants`, `rarityVariants`. Consume them with the `cn()` merge helper:

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
