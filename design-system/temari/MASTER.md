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

- A voice line is at most two lines of what Temari says. Anything longer is prose, and prose is sans.
- **Hero number:** each section may lead with one number set large in mono (`.text-stat` and up).
  Its unit and target sit beside it at label size in `text-text-3`: `32.3 / 27.9 km`.
- A number never wraps. If a hero number and its target do not fit one line at 375px, drop the
  target to its own label line rather than shrinking the number.

### Spacing

The `--pad-*` roles and section rhythm in [design-tokens.md § Spacing](../../docs/design-tokens.md).
A lane divider takes the section gap on both sides.

### Shadow Depths

Flat by default. Sections have no elevation. `shadow-e2` and up stay reserved for floating UI,
sheets and modals.

---

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

### Run rows

- A 3px effort stripe on the leading edge, square corners, colored per the effort table.
- The comparison **verdict stays a chip** (`holding`, `more work`, …). The stripe never encodes the
  verdict.
- Pace, distance and heart rate are mono; the route/date label is sans.

### Plan week strip

- Tiles take the effort color of the day's session.
- Day status is a glyph, never a color: lucide `Check` = done, `X` = missed, a dot = today.

### Buttons

- One `horizon` CTA per view at most. Secondary actions are ghost or outline.
- Connect buttons (Strava, Telegram) use the standard pill; the vendor logo stays as the icon.
- Every tappable carries `.pressable`.

### Floating UI

Popovers, sheets and modals keep `surface-elev` + `shadow-e2`…`e4`
([design-tokens.md § Elevation](../../docs/design-tokens.md)).

---

## Style Guidelines

**Keywords:** editorial, telemetry, race bib, lane lines, quiet surfaces, one loud number

- Whitespace and dashed lanes do the structuring; boxes are the exception.
- Lime is scarce: the CTA, the active nav item, and "earned" states only.
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
- [ ] Sections split by lane dividers; no nested cards
- [ ] Every number in mono with `tabular-nums`; no number wraps at 375px
- [ ] Serif only on voice lines and page titles
- [ ] Every run row / plan tile shows its effort; verdicts and statuses are words or glyphs
- [ ] At most one `horizon` CTA in view
- [ ] `-ink` tokens for colored text; contrast guards pass (`composer gate`)
- [ ] Focus rings visible; `prefers-reduced-motion` respected
- [ ] No horizontal scroll at 375px; nothing hidden behind the bottom nav
- [ ] Responsive: 375px, 768px, 1280px, 2048px
