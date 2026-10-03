# Design System Master File

> **LOGIC:** When building a specific page, first check `design-system/temari/pages/[page-name].md`.
> If that file exists, its rules **override** this Master file.
> If not, strictly follow the rules below.

---

**Project:** Temari, a running companion that compares every run with your own past runs
**Style:** Pewter, editorial + track
**Stack:** Inertia 2, React 19, TypeScript, Tailwind v4, lucide-react icons

This file owns **composition**: how screens are laid out, which face speaks, how a run row reads.
Token **values and mechanics** (the palette, fill/`-ink` split, derived grounds, contrast guards,
radius, elevation, motion) live in [docs/design-tokens.md](../../docs/design-tokens.md), and copy
lives in [docs/voice-and-tone.md](../../docs/voice-and-tone.md). If a rule here needs a token that
does not exist, add it there first.

---

## Global Rules

### Grounds

Two grounds, always. Every surface is checked on light **and** dark before it ships. Use the
ground-reactive semantic classes (`bg-background`, `bg-card`, `bg-secondary`, `text-foreground`,
`text-text-2`, `text-text-3`, `border-border`). Fixed-dark sky panels pin their text explicitly.

### Color Palette

Pewter is unchanged: sky (cold near-black), horizon (lime), cream (cold near-white), plus the
leaf / citrus / ember semantic hues. Values: [design-tokens.md § Colors](../../docs/design-tokens.md).

| Role | Token | Notes |
|------|-------|-------|
| CTA | `bg-horizon` + `text-sky` | Never white or `text-foreground` on lime |
| Page | `bg-background` | |
| Stat tile | `bg-secondary` | The only filled block inside a section |
| Divider | `border-border`, dashed | The lane line |
| Focus | `.focus-ring` / `.focus-ring-on-sky` | |

### Effort colors

Every run row and plan tile carries its **effort**, and color means effort everywhere it marks a run.

| Effort | Source | Stripe / tile |
|--------|--------|---------------|
| easy | `SessionType::Easy` | `leaf` |
| steady | `SessionType::Long`, `SessionType::Tempo` | `citrus` |
| hard | `SessionType::Interval`, `SessionType::Race` | `ember` |
| rest | `SessionType::Rest` | `border`, dashed |
| unknown | no plan match and no heart rate | `border`, solid |

A run's effort is its matched planned session's type. An unplanned run is classified from its
heart-rate zones (#1266). The fill carries the color; any label beside it uses the `-ink` member.

### Typography

Three faces, one job each. No fourth face.

| Face | Class | Speaks |
|------|-------|--------|
| Fraunces italic | `font-serif` | **Temari's voice lines** (the one-liner, the verdict headline) and **page titles** |
| Plus Jakarta Sans | `font-sans` | UI, controls, and multi-sentence narrator prose (`.narration`) |
| JetBrains Mono | `font-mono` + `tabular-nums` | **Every number**, uppercase labels, timestamps |

- **Section headings are mono uppercase eyebrows** (`.text-label-small`), never Fraunces. The serif
  stays reserved for Temari's voice lines and page titles.
- A voice line is at most two lines of what Temari says. Anything longer is prose, and prose is sans.
- **Hero number:** each section leads with **one** number set large in mono (`.text-stat` and up).
  Its unit and target sit beside it at label size in `text-text-3`: `32.3 / 27.9 km`. Every other
  number in the section is a plain stat line beneath the hero; tiles are for secondary numbers
  only, and only once two or more of them sit side by side (see Stat tiles).
- A number never wraps. If a hero number and its target do not fit one line at 375px, drop the
  target to its own label line rather than shrinking the number.

### Spacing

The `--pad-*` roles and section rhythm in [design-tokens.md § Spacing](../../docs/design-tokens.md).
A lane divider takes the section gap on both sides.

### Shadow Depths

Flat by default. Sections have no elevation. `shadow-e2` and up stay reserved for floating UI,
sheets and modals.

---

## Component Catalogue

`/devtools/design` is the living catalogue: every reusable component with its named states, a
variant matrix read from the cva maps, and a copyable usage snippet, each drawn on the light and the
dark ground side by side. Check it before building UI, so a screen reaches for the existing
component instead of hand-rolling a near copy.

- An entry is a co-located `*.examples.tsx` beside the component, discovered by
  [entries.ts](../../resources/js/components/catalogue/entries.ts); its shape is `CatalogueEntry` in
  [catalogue.ts](../../resources/js/lib/catalogue.ts).
- In scope: all of `ui/` and `temari/`, the app-shell banners, and every component used on two or more
  screens from two or more call sites. [structure.test.ts](../../resources/js/test/structure.test.ts)
  fails for one without an examples file, or whose examples never use one of its component exports.
- A state that reads shared props passes them as `sharedProps`; one that opens an overlay sets
  `overlay`, so the overlay opens inside its own ground frame.
- Show only what a product screen uses. A variant no caller uses is deleted, not catalogued.

## Component Specs

### Sections

- A page is a column of **sections separated by lane dividers** (`border-t border-dashed border-border`),
  not a stack of bordered cards.
- A section opens with a mono uppercase eyebrow (`.text-label-small`), then its hero number or
  voice line, then detail.
- **No card inside a card.** Nothing is nested inside a bordered surface.

### Stat tiles

- Two or more side-by-side numbers go in tiles: `bg-secondary`, `rounded-sm`, no border, no shadow.
- A tile holds one eyebrow and one number. Tiles are the only filled blocks inside a section.
- A lone secondary number is a plain stat line, never a single tile. A section's hero number is
  never tiled either — it sits large and untiled above whatever follows.

### Run rows

- A 3px effort stripe on the leading edge, square corners, colored per the effort table.
- The comparison **verdict stays a chip** (`holding`, `more work`, …). The stripe never encodes the
  verdict.
- Pace, distance and heart rate are mono; the route/date label is sans.

### Plan week strip

- Tiles take the effort color of the day's session.
- Day status is a glyph, never a color: lucide `Check` = done, `X` = missed, a dot = today.

### Calendar cells

- Each day is a small bar on a baseline, not a bordered box: bar height is the day's distance
  relative to the month's longest day (a minimum visible height keeps a short run legible), bar
  colour is effort per the effort table. The distance prints above the bar, the date below it.
  Mood does not appear on the grid — only as a word, in the multi-run sheet and the run detail.
- Several runs in one day stack the bar into one segment per run, bottom-up in the order they were
  run (earliest at the bottom), each segment sized to that run's own distance and coloured by that
  run's own effort, with a small gap between segments — never collapsed to one summed bar or the
  hardest effort's colour. The distance printed above the bar is still the day's total.
- A planned rest day with no run gets the dashed rest marker; a day with no run and no plan gets
  only the baseline and a muted date.
- Today's date carries a lime ring, never a fill.

### Buttons

- One `horizon` CTA per view at most. Secondary actions are ghost or outline.
- Connect buttons (Strava, Telegram) use the standard pill; the vendor logo stays as the icon.
- Login's connect is the page's one `horizon` CTA; every in-app Strava reconnect
  (zone banner, profile chip, sync button, demo modal) stays `outline`.
- Every tappable carries `.pressable`.

### Lists

Notification and settings lists are flat rows or lanes, never a card per item.

### Floating UI

Popovers, sheets and modals keep `surface-elev` + `shadow-e2`…`e4`
([design-tokens.md § Elevation](../../docs/design-tokens.md)).

---

## Style Guidelines

**Keywords:** editorial, telemetry, race bib, lane lines, quiet surfaces, one loud number

- Whitespace and dashed lanes do the structuring; boxes are the exception.
- Lime is scarce: the CTA, the active nav item, "earned" states, and the today marker only.
- Two atmospherics are sanctioned: the Login hero's sky→horizon glow, and the soft
  lime halo behind Temari on Onboarding's "you're connected" step, an earned moment.
- The share card art is exempt from these rules (see the card-art exemption in design-tokens.md).

### Page Pattern

1. Page title (Fraunces italic) or the Today voice line
2. Sections, each: eyebrow → hero number or voice line → tiles / rows
3. Lane divider between sections
4. Bottom nav

---

## Anti-Patterns (Do NOT Use)

- ❌ A card nested inside a card
- ❌ A bordered card per section
- ❌ Color as the only verdict or status signal
- ❌ Effort colors used for anything other than effort
- ❌ Sans or serif numbers
- ❌ Serif on multi-sentence prose
- ❌ Section headings in serif
- ❌ A fourth typeface
- ❌ A fill color used as text (use its `-ink`)
- ❌ Dropping the light ground or the dark ground

### Additional Forbidden Patterns

- ❌ **Emojis as icons.** Use lucide-react.
- ❌ **Layout-shifting hovers.** Press feedback is `.pressable` only.
- ❌ **Text under 11px** outside the card art.
- ❌ **Gradient text.**

---

## Pre-Delivery Checklist

Before delivering any UI code, verify:

- [ ] Checked on light **and** dark
- [ ] A new or changed reusable component has its `*.examples.tsx` up to date
- [ ] Sections split by lane dividers; no nested cards
- [ ] Every number in mono with `tabular-nums`; no number wraps at 375px
- [ ] Serif only on voice lines and page titles
- [ ] Every run row / plan tile shows its effort; verdicts and statuses are words or glyphs
- [ ] At most one `horizon` CTA in view
- [ ] `-ink` tokens for colored text; contrast guards pass (`composer gate`)
- [ ] Focus rings visible; `prefers-reduced-motion` respected
- [ ] No horizontal scroll at 375px; nothing hidden behind the bottom nav
- [ ] Responsive: 375px, 768px, 1280px, 2048px
