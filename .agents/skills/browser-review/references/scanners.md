# Scanners

### Why `contrast.mjs` exists, and why it is not the design page's audit

`/devtools/design` scores *token pairings* declared in `grounds.json`. That answers "is this pair
readable", not "is anything on screen unreadable" — a token can be perfectly specified and still be
applied to the wrong surface. `contrast.mjs` scores what the browser actually painted: every element
with its own text node, its background resolved by walking ancestors, against the WCAG minimum for
its computed font size and weight.

Run it once per ground, because the two disagree — three real bugs shipped
under a token audit that read green on light, all of them a fixed-identity token used where the
ground flips (a `mood-*` fill is fixed, `foreground` is not, so `text-foreground` on a mood chip is
near-white on pale green).

An element whose background is a gradient, image, map tile or video is **skipped, not scored** —
there is no flat colour to compare against, and scoring it against an ancestor's colour invents
failures that are not on screen. That was the difference between four reported failures and the
three that were real.

Colours resolve through the shared canvas helper in `scans.mjs`, and every translucent layer between
the text and the first opaque ancestor is composited. Its regex-only parser once read the "Activate
map" pill's `bg-ink/70`, which Chromium reports as `oklab(... / 0.7)`, as transparent and scored
cream on the slab behind it at 1.13 on the light ground; the pill measures 6.75 there. The clean
baseline is a **total of 0 on both grounds**; anything above that is new.

### `mounts.mjs` and `light-islands.mjs` — the two questions a ratio can't answer

`/devtools/design` worst-cases every translucent panel against every ground the app paints, because
`grounds.json` records the mount as `paper` and `paper` is a *set*. That answers "could this pairing
fail", never "does it". **`mounts.mjs`** resolves the other half: for each `bg-<token>/<alpha>` spec
you pass it, it walks the rendered DOM of every discovered page and reports the nearest opaque
ancestor background per call site. A shortfall scored against the worst ground can then be re-scored
against the ground the component is actually mounted on. Run it before tuning a token: of 11 dark
shortfalls it was pointed at, six rendered only on `background`/`card` and passed there (4.6-5.9),
three came from an unused vendored variant, and two rendered only on `/devtools/design` itself.

Two traps it has already sprung. Alpha panels are written **both** ways, `bg-leaf/15` and
`bg-leaf/[0.18]`, so a grep for one silently misses the other and reports a live panel as dead. And a
`hover:` surface has to be hovered to exist — `contrast.mjs` never hovers, so several panels that
read as "never rendered" are simply never rested on.

**`light-islands.mjs`** reports geometry rather than contrast, which is the gap both audits share: a
fixed-light token (`cream`, `cream-deep`, `line`, the `.skeleton` utility, a `mood-*-bg` cell) used
where a reactive one was meant renders as a bright island on a near-black page and **nothing fails**,
because the dark text on it still clears AA. It flags every element whose own background is far
lighter than the ground beneath it, hovering anything that carries a `hover:bg-` utility on the way.
Read the head of its output: vivid accent fills (`horizon`, `citrus`, `mood-*` dots) are fixed
identity by design and legitimately sit near the top, so what you want is anything *near-white*.
`/devtools/design` dominates the list and should be ignored wholesale — rendering every token as a
swatch, fixed-light ones included, is that page's entire job.

**`edges.mjs`** asks `light-islands.mjs`'s question of a *border* rather than a surface, which is the
other half nothing scores: the token audit scans `bg-<token>` only, so a fixed-light border token on
a dark ground fails nothing. It does not go unreadable, it goes **absent** — `border-ink/[0.18]` over
a Sky card measured 1.02:1, and the selected-colorway indicator in `ShareCardModal` was `#171f28` on
`#171f28`. Two things it gets right that are easy to get wrong: an edge is resolved against what is
**outside** the element, since scoring it against the element's own background reports the deliberate
`border-x bg-x` sizing trick as invisible; and colours go through a **canvas** rather than a regex,
because computed styles come back as `oklab()` and `color-mix()` as often as `rgb()` and a regex that
only knows `rgb()` reads a 1.02:1 border as "no data" instead of "invisible".

It scores **borders**; ring/box-shadow detection is best-effort and misses Tailwind's composed shadow
chain, so the elevation rim is not scored — deliberately, since elevation sits below the separator
floor on both grounds (1.28:1 dark rim, 1.11:1 light cast, against a 1.4 minimum meant for dividers).

Its known-clean baseline is **Leaflet's own zoom control on both grounds**, plus `border-border/60` on
`/settings` at 1.31 on light — `--color-border` is derived to land exactly on 1.4:1, so any alpha
below full is inherently under the floor.

**`states.mjs`** runs those scans in states a page load never reaches, which is where the coverage
keeps failing: the `.skeleton` bug lived in a loading state, and three of #723's invisible edges lived
inside a collapsed accordion and an unopened modal. All of them passed clean sweeps.

It **discovers its own triggers** — `aria-expanded`, `aria-haspopup`, `aria-controls`, `summary` — so
a new modal is covered the day it ships. A maintained list would drift the moment nobody updated it,
which is the same failure mode as the `onSky` prop nine of nine call sites forgot. Findings present
before the click are subtracted, so it reports what the *state* introduced.

It immediately found the worst bug of the audit: `MetricExplainer`'s popover painted a near-white
gradient with near-white body text at **1.00:1** on the dark ground, across three call sites on the
dashboard and history. Invisible to everything else twice over — it only exists when opened, and its
background is a **gradient**, which `contrast.mjs` skips by design and an island scan misses because
`backgroundColor` on a gradient element is transparent. A gradient is still a blind spot; the driver
only caught this one via its border.

Its known-clean baseline is **one island on `/race`**: the selected date cell's `bg-horizon`, fixed
identity by design. Light ground is clean.

`scans.mjs` holds the colour maths every scanner shares, so a fix lands everywhere at once —
`light-islands.mjs` and then `contrast.mjs` were still parsing colours with a regex and silently
dropping every `oklab()` background until each was pulled onto the shared canvas resolver.
