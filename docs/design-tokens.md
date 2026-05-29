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

| Token | Family | Use |
|---|---|---|
| `font-display` | Instrument Serif (italic) | Headlines, Temari voice / quotes |
| `font-sans` | JetBrains Mono | Body, UI, numbers (brand is all-mono) |
| `font-mono` | JetBrains Mono | Same family as `font-sans` (intentional) |

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
