## Design system

Pewter: cold near-white paper, near-black structure, lime accent. Tokens are declared in the
`@theme static` block of [resources/css/app.css](../../../../resources/css/app.css), which owns every
emitted value; [build-tokens.mjs](../../../../resources/brand/build-tokens.mjs) owns the colour
*derivation* rules behind it (the fill/text split and the per-ground `-ink` tiers), not the radius,
spacing, elevation or type scales. Full reference (colors, type scale, fonts, radius, elevation,
spacing) in [docs/design-tokens.md](../../../../docs/design-tokens.md). Composition (lane-divided
sections, no nested cards, effort colors on run rows, which face speaks) is owned by
[design-system/temari/MASTER.md](../../../../design-system/temari/MASTER.md), which the ui-ux-pro-max
skill also reads. Follow it for any screen work.
Use the **semantic token families, never raw Tailwind colors** like `lime-500`:

- `sky` (`#171f28`) / `sky-deep` (`#0b1017`) / `sky-2` (`#26303d`) — structure, dark hero panels, and (since F2) the dark ground itself. Cold near-black.
- `horizon` / `horizon-deep` (`#ade047` lime) — primary CTA, "earned"/PR state, Temari accent.
- `cream` / `cream-deep` (`#f1f5f8`) — paper / secondary surface and borders. Cold near-white.
- `ink` / `ink-2` / `ink-3` — the 3-tier text-contrast scale (see below).
- `surface` / `surface-card` / `surface-elev` / `surface-warm` / `surface-sunken` + `line` / `line-strong` — app surfaces (dawn-shift drifts `surface`).
- `mood-{blazing,easy,wobbly,gassed,overloaded,chill}` (each with a pastel `-bg` cell tint and an `-ink` label variant) — calendar cells + mood badges.
- `rarity-{common,uncommon,rare,epic,legendary}` (each with an `-ink` label variant) — card rarity.
- semantic hues `leaf` / `leaf-deep` / `leaf-ink`, `ember` / `ember-deep` / `ember-ink`, `citrus` / `citrus-ink`, `stone` (`-deep` fills a dark CTA, `-ink` carries the label; `citrus` fills no CTA and has no `-deep`).

`citrus` (`#c9971f`) is reserved for PR / legendary celebrations only.

**Which mechanism flips a value.** A ground difference in a **colour** is a semantic token,
never a `dark:` utility (`bg-card`, not `bg-cream dark:bg-sky`) — the token layer has one
definition site, is classified fixed-vs-reactive by `grounds.json` so the audit scores it against
the right grounds, and cannot drift into a raw palette shade. A difference that is **not** a colour
value — an opacity, a ring width, a whole property — may use `dark:`, because no token can hold it;
[MascotWatermark.tsx](../../../../resources/js/components/temari/MascotWatermark.tsx#L26)'s `dark:opacity-20` is the canonical case. `dark:` is wired to `data-theme`, not
`prefers-color-scheme`. See [tokens-flip-colour-dark-variant-flips-the-rest](../../../../docs/decisions/tokens-flip-colour-dark-variant-flips-the-rest.md).

**Two grounds, since F2.** `[data-theme="dark"]` on `<html>` inverts Sky and Cream — Sky becomes
ground, Cream becomes text. Neither ground is the default: with nothing stored the app resolves
from `prefers-color-scheme`, and an explicit light or dark is reachable from Settings. A second
semantic layer (`background`/`foreground`/`card`/`popover`/... plus
`leaf-ink`/`ember-ink`/`citrus-ink`/`rarity-*-ink`, which invert per ground) sits above the palette
above; see "Ground-reactive semantic layer" in [docs/design-tokens.md](../../../../docs/design-tokens.md).

**Fill vs text.** Every saturated family ships as a pair: the vivid value is the fill (dots,
frames, strokes, tinted cells), the derived `-ink` value is the only member allowed to carry text
or an icon on paper. `text-rarity-legendary` is always wrong; it is `text-rarity-legendary-ink`, and
`text-leaf-deep` / `text-ember-deep` / `text-horizon-deep` are wrong the same way. The two fills too
light to reach 3:1 (legendary gold, uncommon green) keep their vibrancy and are drawn with a 2px
`-ink` outline rather than being darkened. On a **dark** ground the split inverts: the vivid fill is
the readable label there (`text-leaf` on a sky panel), so an `onSky` branch keeps it.

**Radius, elevation, spacing** are scales now, not call-site guesses: `rounded-panel` (26px) is the
card corner, `shadow-e1`..`e4` is resting → floating → sheet → modal (warm-tinted, never
Tailwind's neutral defaults), and padding names a role (`.pad-chip` / `.pad-panel` / `.pad-card` /
`.pad-hero` / `.pad-page`). `npm run check:palette` rejects raw palette shades, default shadows and
off-scale radii; `/devtools/design` renders the whole set plus a live contrast audit read out of
the shipped CSS.

**Screen rules.**
- Hide (don't disable) a control that can't act in the current state; enforce it at the server,
  the shared prop and the UI, and never toast success for work that won't happen.
- When unsure which computed value to show, show the raw facts (prescribed vs actual), each number
  labelled with its own word.
- Show layout options beside a neighbouring page and flag any that break the shared container; ask
  what fields a row shows before building it.
- Size and offset absolutely placed decorations in `rem`, never px attributes: the root font grows
  at 1280px and 2048px.
- `BareShell`/Login never imports framer-motion; animate it with CSS `@keyframes`
  (`npm run check:chunks` enforces the entry-chunk budget).

### Strava brand mark

Every Strava (and Telegram) connect/reconnect button uses the standard pill
(`PillButton` / `pillButtonVariants` in
[variants.ts](../../../../resources/js/lib/variants.ts)), with the vendor logo kept as the icon —
the button chrome itself is themed like any other pill, and the dedicated `strava-orange` tokens
are retired. Within any card that **displays the Strava brand mark**, keep other warm accents off
it: switch the local context to neutral (`surface-sunken` + `ink`) so the brand mark gets
breathing room. Strava can revoke API access for brand-guideline violations, a risk the owner
accepted knowingly when the buttons moved onto the pill (#1271).

### CTA contrast rule (WCAG)

`horizon` (`#ade047`) is a lime tone, so it pairs with **dark** text, never white. Follow the
[`PillButton`](../../../../resources/js/components/ui/PillButton.tsx) presets:
There are **four tones**, defined once in [`pillButtonVariants`](../../../../resources/js/lib/variants.ts#L52):
- `horizon` bg → **`text-sky`**, a fixed value rather than the ground-reactive `text-foreground`. This is deliberate and the one place the semantic layer must not be used: `foreground` flips to cream on the dark ground, which is the unreadable pairing on lime. Hover darkens to `horizon-deep`.
- `sky` bg (near-black) → `text-cream` (passes ~15:1+); hover darkens to `sky-deep`.
- `ghost` → transparent with an `ink`-tinted hairline; `outline` → `bg-card` with a `border` edge and `text-text-2`.
- Never put white text on `horizon`/`citrus`/`cream` (all too light).

### Gradient primitives

**There is no gradient-text primitive.** `GradientText` clipped a `linear-gradient` to a number at
display sizes; the prototype draws no gradient text on any screen, so `W2` swept it. Don't
reintroduce one for a stat: a display-tier number already carries the emphasis.
Backdrop atmospherics (e.g. the login page) are inline CSS
`linear-gradient` + `radial-gradient` layers on the sky→horizon ramp, not a shared component;
in-app pages stay clean.

### Text contrast tiers

3-stop semantic system — use the tier that matches the text role, not "pick whichever color looks right".
Since F3, call sites write the ground-reactive semantic classes (backed by `--color-ink` on the
light ground, `--color-cream` on dark) rather than the raw `text-ink*` utilities, which still exist
underneath but are fixed to the light value:

- `text-foreground` (`#16181b` on light) — **primary text**: body paragraphs, headings, button labels, KPI values. Default for any prose the user reads.
- `text-text-2` (`#34373c` on light) — **supporting body**: page subtitles, briefing suggestion lines, descriptive paragraphs adjacent to a primary statement.
- `text-text-3` (`#60666d` on light) — **labels-above-values, timestamps, footnotes, table column headers, secondary metadata**. Smallest contrast tier, never use for body prose.

`text-text-3` must not wrap running prose (`<p>`).

### Typography & fonts

Three families (self-hosted via
[fonts.css](../../../../resources/css/fonts.css)): **Fraunces** italic is
`font-serif` (page titles and Temari's one- or two-line voice lines; multi-sentence narrator prose is sans; renamed from
`font-display` in F3 to match the prototype's own token name); **Plus Jakarta Sans** is `font-sans`, the default
family for body/UI/buttons; **JetBrains Mono** is `font-mono`, for *numbers, stats and small
uppercase metadata labels* (section labels, chips, stat-tile / card captions, timestamps). Oswald
(`font-collectible`) is retired: the Card uses the same stack as everything else. Because `font-sans` is Tailwind's default, every small uppercase label must carry an
**explicit `font-mono`** (or the `.text-label-micro` / `.text-label-small` utilities) or it falls back to
the sans. Keep `tabular-nums` on numeric / stat displays.
The scale is fluid `clamp()` tokens in `app.css` (`text-display-*`, `text-headline-*`,
`text-quote-*`), each bundling its own line-height + letter-spacing, so one utility lands the full spec.

| Role | Class |
|---|---|
| In-app hero title | `font-serif italic text-display-2xl text-foreground` |
| Page title (`<h1>`) | `font-serif text-display-lg text-foreground` (compact/devtools header: `text-headline-xs`) |
| Section heading (`<h2>`) | `font-serif text-headline-sm text-foreground` |
| Temari voice line (one-liner, verdict headline) | `font-serif italic text-headline-sm text-foreground` |
| Narrator prose | `.narration` (`font-sans text-quote-sm leading-relaxed text-foreground`) |
| Narrator prose, compact | `.narration-dense` (`font-sans text-[12px] leading-[1.45] text-foreground`) |
| Sub-label (KPI/table cap) | `font-mono text-xs font-semibold uppercase tracking-wider text-text-3` |
| Body paragraph | `font-sans text-sm leading-relaxed text-foreground` |
| Caption / supporting | `text-sm text-text-2 leading-relaxed` |
| Meta / timestamp | `text-xs text-text-3` |
| KPI / big stat value | `.text-stat` (mono, `tabular-nums`); larger hero numbers stay `font-mono` at a display-tier size; avoid one-off `text-[NNpx]` |

### Section spacing rhythm

- Major section → next major: `mt-10`
- Subsection → next: `mt-6`
- `<h2>` → content: `mt-3`
- Page header → first section: `mt-8`
- Card padding names a role, never a number: `.pad-hero` (24px) for hero cards, `.pad-card` (16px) for data cards, `.pad-panel` (12/16px) for dense rows, `.pad-chip` for chips and pills
