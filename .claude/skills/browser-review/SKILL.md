---
name: browser-review
description: Drive a real browser to screenshot every user-facing page across a mobile/tablet/laptop/desktop viewport matrix, capture console errors, and audit for horizontal overflow — an end-to-end visual UI review. Use when asked to "browser review", "screenshot every page", "mobile UI review", "check the UI on mobile/tablet", "full browser check", or "review the app end to end" in this repo.
---

# browser-review

End-to-end visual review: log in as the demo user, **discover every page from the route table**,
screenshot each across the viewport matrix, collect JS/console errors, and flag any horizontal overflow.
Then read the PNGs back to spot layout bugs. Everything runs **inside the Sail `app` container**
(no host browser needed), so the page list is never hardcoded — it comes from
`php artisan route:list` each run and auto-includes new pages.

## Viewport matrix (default)

The app has **one** nav chrome at every width — `MobileTopBar` + `MobileBottomNav`. The port deleted
the desktop `TopNav`, so no viewport here swaps chrome; what they cover is width-driven layout and
the two type steps (`1280px` -> 19.2px, `2048px` -> 21.6px):

| key | size | what it covers | in default sweep? |
|-----|------|-----------|--------------------|
| `mobile`  | 390×844  (iPhone 13)   | base type (16px) | yes |
| `se`      | 320×568  (iPhone SE)   | base type (16px) | yes — narrowest real device, catches width-driven bugs `mobile` misses |
| `tablet`  | 834×1112 (iPad portrait) | base type (16px) | no — nothing disagrees with `mobile` here, opt in explicitly |
| `laptop`  | 1920×1080              | first type step (19.2px), past every column breakpoint | yes |
| `desktop` | 2560×1440 (2K)         | **second type step (21.6px)** — the only viewport past 2048px | yes |

Default is `mobile,se,laptop,desktop` — two phones and the two real desktop sizes. `laptop` and
`desktop` differ only by the 2048px type step, which is the point: one takes 19.2px, the other
21.6px. The old 1280 and 1536 entries are gone because 1920 is already past every breakpoint they
tested (`lg`, the 1280 column widening, and the `2xl` page cap), so they only cost screenshots.

`tablet` is dropped from the default because nothing disagrees with `mobile` there. `se`, in
contrast, is kept despite sharing `mobile`'s chrome: its narrower 320px width has caught real
overflow that 390px missed entirely — a CSS grid track sized to its widest child instead of shrinking
to fit, a fluid font clamp whose floor was tuned for a wider column and silently ellipsis-truncated
real values. Those are width-driven bugs, not breakpoint-driven ones, so they don't reproduce at
390px. Narrow with `VIEWPORTS=mobile`, or take the full five-way matrix with
`VIEWPORTS=mobile,se,tablet,laptop,desktop` before a release.

Nothing covers 900–1279px, and nothing did before either — worth knowing rather than assuming the
matrix is exhaustive.

## Prerequisites

```bash
./vendor/bin/sail up -d
./vendor/bin/sail npm run build               # fresh built assets — stale/missing build = Vite manifest errors or old UI
./vendor/bin/sail artisan demo:seed          # demo user + ~126 runs, deterministic
# .env must have DEMO_LOGIN_ENABLED=true (the scripts log in via the /login demo button)
```

The app is reachable **inside the container at `http://localhost`** (host-forwarded port is
`APP_PORT=7001`, but the scripts run in the container, so use `localhost`).

### The operator console (`/devtools`, `/devtools/design`, `/devtools/ai-usage`, `/pulse`)

**All four are swept by default, and locally they need no password.**
[EnsureDevtoolsAccess](../../../app/Http/Middleware/EnsureDevtoolsAccess.php) returns early when
the app is not in production, so an unauthenticated request to `/devtools/design` answers **200**.
`/pulse` is a vendor route `route:list --except-vendor` never reports, so it is appended by hand.

This skill used to gate all four on `DEVTOOLS_PASSWORD` being set, which kept `/devtools/design`
out of every audit it runs — the one page that renders the token swatches an audit is most likely
to ask about. Do not reintroduce that gate. `DEVTOOLS_PASSWORD` is needed only to point these
scripts at a production host, where Basic Auth does apply:

```bash
./vendor/bin/sail exec -e DEVTOOLS_PASSWORD=<pw> app node .claude/skills/browser-review/scripts/shoot.mjs
```

## The Alpine/Playwright gotcha (do not rediscover this)

The `app` container is **Alpine Linux (musl), ARM64**. Playwright's bundled Chromium is a glibc
build and fails to launch with a misleading `spawn ... ENOENT`. Fix: use Alpine's **native** musl
Chromium and point Playwright at it. `setup.sh` does this:

- `apk add --no-cache chromium nss freetype harfbuzz ttf-freefont` (needs **root**) → `/usr/bin/chromium`
- `npm i playwright --no-save` for the JS driver only, run as the **app user** (not root, or the
  unprivileged teardown can't remove it); `teardown.sh` deletes the playwright dirs to restore the
  lockfile state
- launch with `executablePath: '/usr/bin/chromium'` + `--no-sandbox --disable-dev-shm-usage`

Both are **ephemeral** (gone when the container is recreated) — this skill never commits browser
binaries or edits `package.json`.

## Run it

```bash
# 1. one-time setup per container lifetime (apk needs root)
docker compose exec -u root app sh .claude/skills/browser-review/scripts/setup.sh

# 2. screenshots across the viewport matrix (default mobile,se,laptop,desktop — see Viewport matrix above)
./vendor/bin/sail exec app node .claude/skills/browser-review/scripts/shoot.mjs
#    e.g. just phone:    VIEWPORTS=mobile ./vendor/bin/sail exec -e VIEWPORTS=mobile app node .../shoot.mjs
#    e.g. full 5-way:    VIEWPORTS=mobile,se,tablet,laptop,desktop ./vendor/bin/sail exec -e VIEWPORTS=mobile,se,tablet,laptop,desktop app node .../shoot.mjs

# 3. horizontal-overflow audit across the matrix (run BEFORE Inspect — its output gates which
#    pages get the expensive vision read, see "Inspect in parallel" below)
./vendor/bin/sail exec app node .claude/skills/browser-review/scripts/audit.mjs

# 4. rendered-contrast audit, once per ground (dark is the app's default)
./vendor/bin/sail exec app node .claude/skills/browser-review/scripts/contrast.mjs dark
./vendor/bin/sail exec app node .claude/skills/browser-review/scripts/contrast.mjs light

# 5. on demand: is a design-page shortfall real, and is any surface wearing the wrong ground?
./vendor/bin/sail exec app node .claude/skills/browser-review/scripts/mounts.mjs dark 'bg-leaf/15,...'
./vendor/bin/sail exec app node .claude/skills/browser-review/scripts/light-islands.mjs dark

./vendor/bin/sail exec app node .claude/skills/browser-review/scripts/edges.mjs dark

# 6. teardown (restore node_modules; screenshots are kept as history)
./vendor/bin/sail exec app sh .claude/skills/browser-review/scripts/teardown.sh
```

### Why `contrast.mjs` exists, and why it is not the design page's audit

`/devtools/design` scores *token pairings* declared in `grounds.json`. That answers "is this pair
readable", not "is anything on screen unreadable" — a token can be perfectly specified and still be
applied to the wrong surface. `contrast.mjs` scores what the browser actually painted: every element
with its own text node, its background resolved by walking ancestors, against the WCAG minimum for
its computed font size and weight.

Run it per ground. The two disagree, and the dark ground is the default — three real bugs shipped
under a token audit that read green on light, all of them a fixed-identity token used where the
ground flips (a `mood-*` fill is fixed, `foreground` is not, so `text-foreground` on a mood chip is
near-white on pale green).

An element whose background is a gradient, image, map tile or video is **skipped, not scored** —
there is no flat colour to compare against, and scoring it against an ancestor's colour invents
failures that are not on screen. That was the difference between four reported failures and the
three that were real.

**One known false positive remains**, on the light ground only: the "Activate map" overlay on
`/activities/{id}`. Nothing in its ancestor chain paints an opaque background, so the resolver falls
back to white and scores `text-cream` against white (1.13). In the browser it sits on a dark
`bg-ink/70` pill over the map placeholder and is perfectly legible. Treat a **dark-ground total of 0
and a light-ground total of 1** as the clean baseline; anything above that is new.

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

> **Reading screenshots costs more than it looks.** An image read into the main context is re-billed
> as a cache read on *every* later turn, so cost is `size x remaining turns`, not size. A full-page
> mobile shot is ~1170x2532 real pixels (deviceScaleFactor 3). Three rules:
> 1. **Read each image at most once.** If you need it again, re-read your own notes, not the file.
> 2. **Let a subagent look and report in text** — that is what the Inspect phase below does, and why
>    it is structured as disjoint per-viewport sets rather than several agents over the same files.
> 3. **Cropping for a closer look: crop AND downscale in one step, and write `.jpg`.** Never write a
>    full-resolution intermediate you then read. `sips -Z 900 -s format jpeg -s formatOptions 80 in.jpg
>    --out crop.jpg` (or one PIL call). Ad-hoc `crops/*.png` have historically been the single largest
>    source of oversized reads after the sweep itself.

Each run lands in its own batch dir, keyed by date + execution time:
`storage/app/browser-review/<YYYY-MM-DD>/<HHMMSS>/<viewport>/NN-<page>-{viewport,full}.jpg`. `shoot.mjs`
clears prior batches at the start, so only the latest sweep is on disk, and prints the resolved dir as
`BATCH_DIR=...` on its last line — **capture that and pass it to the inspect workflow.** The script also
prints any console/`pageerror` per page. The audit prints a human-readable `HORIZ-OVERFLOW=true/false`
line per page per viewport (ignoring intentional `overflow-x-auto` scroll containers and decorative
`pointer-events-none` glow blobs) plus a machine-parseable `AUDIT vp=<viewport> name=<page-slug>
overflow=<true|false>` line for every page — **capture and parse these too**, they gate the Inspect
phase below (`name` matches the `-<name>-full.jpg` slug in `shoot.mjs`'s filenames, so the two scripts'
independent page orderings don't need to line up). The overflow flag is `true` if *either* the
document's `scrollWidth` exceeds the viewport *or* any individual element's box extends past it — the
latter alone still flags a page, since an `overflow-hidden` ancestor can clip a child without growing
`scrollWidth`, which would otherwise hide real off-screen content from the check entirely.

> These PNGs are gitignored (`storage/app/.gitignore` ignores `*`) and your IDE may hide gitignored
> files — they're on disk under `storage/app/browser-review/`, not in a temp dir.

## Inspect in parallel (audit-gated, split across model tiers, keep the main context lean)

A sweep produces a lot of images — **don't read them all into the orchestrating context, and don't
vision-read every page at full reasoning effort.** `audit.mjs` already found horizontal overflow
programmatically for every page; reserve the expensive judgment call for what code can't check.

Run the inspection as a `Workflow` that splits each viewport's pages into two agent calls:

- **Audit-flagged pages → `model: 'haiku'`** *(fast/cheap tier — confirm-only work)*. The overflow is
  already found; the agent just describes what's actually broken on the known-flagged PNG so it's
  fixable. No persona needed — the task is fully specified by the audit flag.
- **A small evenly-spaced sample of non-flagged pages (4 per viewport) → `model: 'sonnet',
  effort: 'medium'`** *(default/capable tier — open-ended judgment)*. These pages passed the automated
  check, so this agent hunts for what code can't detect: overlapping/clipped text, wrong nav chrome,
  off-screen elements, awkward hierarchy. Framed with a short persona ("senior product designer and
  frontend engineer doing a visual QA pass") since the task is genuinely subjective, not yes/no.

If the model roster changes later (e.g. Haiku or Sonnet is retired), swap in whatever fills the same
fast/cheap or default/capable tier at the time — the split above is the instruction, the specific model
names are just today's mapping onto it.

### Verify before acting — a real false-positive rate, not a hypothetical one

A single review pass produced 20 findings; roughly a third didn't survive verification against the
live app (a low-contrast filler element misread as empty space, a label scrolled off-screen misread as
CSS-hidden, cropped screenshot math, a claim about "missing" content that render unconditionally with
no responsive class anywhere near it). Two things reduce this rate:

- **Screenshots come from separate logins.** `shoot.mjs` opens a fresh browser context (and re-logs
  in) per viewport, so mobile and desktop shots of the "same" page are two independent server
  requests. Any `Analysis`-backed content (LLM narration, pending/skeleton/retry states) can
  legitimately differ between the two for reasons that have nothing to do with responsive CSS. Treat a
  content difference in AI-narrated text as lower-confidence than a difference in static UI chrome —
  it may be a request-timing artifact, not a layout bug.
- **"Missing" is a stronger claim than "small/faint/different."** When a finding says content is
  dropped or absent (not just small, low-contrast, or restyled), it should say so explicitly and flag
  it as needing confirmation rather than asserting it as settled fact — that phrasing is what lets a
  HIGH-severity claim get fixed on sight instead of re-verified first.

Before spending an implementation pass on a HIGH-severity "content missing" claim, re-check it live
rather than trusting one screenshot read: navigate to the real page and query the DOM directly (e.g.
`page.evaluate(() => ...)` for text content, or `getComputedStyle(el).width` for a suspiciously-small
element — this caught a flex-shrink bug that squeezed a 6px indicator dot to 0px width, invisible in a
screenshot but obvious in one `getComputedStyle` call). It's a few extra minutes against the running
app, versus an implementation change chasing a symptom that isn't there.

Pass the batch dir, the viewports you shot, and the parsed `AUDIT` lines as `args`, e.g.:
```json
{
  "dir": "storage/app/browser-review/2026-06-19/143022",
  "viewports": ["mobile", "desktop"],
  "pages": {
    "mobile": [{ "name": "today", "overflow": false }, { "name": "activities-detail", "overflow": true }],
    "desktop": [{ "name": "today", "overflow": false }, { "name": "activities-detail", "overflow": false }]
  }
}
```
(`dir` is the `BATCH_DIR=` line `shoot.mjs` printed; `pages[viewport]` is every `AUDIT` line for that
viewport from step 3, `{name, overflow}`; omit `viewports` to use every key in `pages`.) Merge the
results, then open only the flagged PNGs to confirm before acting — and **state the batch dir path in
your final summary to the user** so they can open the PNGs directly without digging through logs.

```js
export const meta = {
  name: 'browser-review-inspect',
  description: 'Confirm audit-flagged pages (haiku) + hunt a small sample (sonnet, medium) per viewport',
  phases: [{ title: 'Inspect', detail: 'flagged pages on haiku, a small non-flagged sample on sonnet' }],
}

const NAV = {
  mobile:  { size: '390x844',  nav: 'mobile chrome (top bar + bottom nav)' },
  se:      { size: '320x568',  nav: 'same chrome, narrowest real device' },
  tablet:  { size: '834x1112', nav: 'same chrome' },
  laptop:  { size: '1280x800', nav: 'same mobile chrome, first type step (19.2px)' },
  wide:    { size: '1536x864', nav: 'same mobile chrome, widest max-w-page-2xl column' },
  desktop: { size: '2560x1440', nav: 'same mobile chrome, 2K, second type step (21.6px)' },
}
const dir = args?.dir ?? 'storage/app/browser-review'
// args.pages: { [viewport]: [{ name, overflow }] } — parsed from audit.mjs's `AUDIT vp=... name=... overflow=...` lines
const pagesByViewport = args?.pages ?? {}
const viewports = args?.viewports?.length ? args.viewports : Object.keys(pagesByViewport)

const FINDINGS = {
  type: 'object',
  additionalProperties: false,
  required: ['viewport', 'findings'],
  properties: {
    viewport: { type: 'string' },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        required: ['page', 'severity', 'issue'],
        properties: {
          page: { type: 'string' },
          severity: { type: 'string', enum: ['high', 'medium', 'low'] },
          issue: { type: 'string' },
        },
      },
    },
  },
}

// Plain JS, no fs needed — the workflow only has the page names/overflow flags passed in via args.
function evenSample(names, k) {
  if (names.length <= k) return names
  const step = names.length / k
  return Array.from({ length: k }, (_, i) => names[Math.floor(i * step)])
}

phase('Inspect')
const calls = []
for (const vp of viewports) {
  const pages = pagesByViewport[vp] ?? []
  const flagged = pages.filter((p) => p.overflow).map((p) => p.name)
  const sample = evenSample(pages.filter((p) => !p.overflow).map((p) => p.name), 4)

  if (flagged.length) {
    calls.push(() => agent(
      `Confirm layout bugs on audit-flagged pages of the "${vp}" viewport (${NAV[vp]?.size}, ${NAV[vp]?.nav}) of ` +
      `the temari app. Read only the *-full.jpg files in ${dir}/${vp}/ whose filename contains one of these ` +
      `page names (match by "-<name>-full.jpg"): ${flagged.join(', ')}. audit.mjs already found horizontal ` +
      `overflow here — describe what's actually broken so it's fixable. Ignore by design: width-capped content ` +
      `(PageContainer / max-w-page-2xl), the fixed bottom-nav mid-page artifact, sparse demo-data grids, and ` +
      `intentional overflow-x-auto. If you're about to say content is "missing" or "dropped" rather than just ` +
      `small, low-contrast, or differently styled, say explicitly that it needs live confirmation (a false ` +
      `positive here costs a wasted implementation pass) — don't assert it as settled fact from one screenshot. ` +
      `Return only pages with a real, describable issue.`,
      { label: `inspect:${vp}:flagged`, phase: 'Inspect', model: 'haiku' /* fast/cheap tier */, schema: FINDINGS }
    ))
  }
  if (sample.length) {
    calls.push(() => agent(
      `You are a senior product designer and frontend engineer doing a visual QA pass on the "${vp}" viewport ` +
      `(${NAV[vp]?.size}, ${NAV[vp]?.nav}) of the temari app. Read only the *-full.jpg files in ${dir}/${vp}/ ` +
      `whose filename contains one of these page names (match by "-<name>-full.jpg"): ${sample.join(', ')}. These ` +
      `pages passed the automated overflow check, so hunt for issues code can't detect: overlapping/clipped/` +
      `truncated text, wrong nav chrome for this viewport, off-screen elements, awkward spacing or hierarchy. ` +
      `Ignore by design: width-capped content (PageContainer / max-w-page-2xl), the fixed bottom-nav mid-page ` +
      `artifact, sparse demo-data grids, and intentional overflow-x-auto. If you're about to say content is ` +
      `"missing" or "dropped" between viewports rather than just small, low-contrast, or differently styled, ` +
      `say explicitly that it needs live confirmation instead of asserting it as fact — mobile and desktop are ` +
      `separate logins, so AI-narrated content in particular can legitimately differ for reasons unrelated to ` +
      `responsive CSS. Return only flagged pages.`,
      { label: `inspect:${vp}:sample`, phase: 'Inspect', model: 'sonnet' /* default/capable tier */, effort: 'medium', schema: FINDINGS }
    ))
  }
}
const results = (await parallel(calls)).filter(Boolean)
log(`Batch dir: ${dir}`)
return results
```

## What the scripts handle for you

- **Page discovery:** `lib.mjs` runs `php artisan route:list --json --except-vendor` and keeps the
  GET `web` pages — dropping apis, oauth handshakes, webhooks, assets, and legacy 301 redirects.
  Add a page and it's covered automatically; nothing to maintain by hand.
- **Auth:** clicks the demo button on `/login` (no Strava needed) — fresh per viewport context.
- **`{param}` pages:** resolved at runtime by scraping the first matching link off the list page
  (e.g. `/activities/{activity}` → `/activities/126`). If a detail page can't be sampled, the data is
  thin — **re-run `./vendor/bin/sail artisan demo:seed`** and try again.
- **Redirect dedupe:** pages reached via a 301 alias are screenshotted once (keyed by the landed URL).
- **Card-reveal modal:** the demo user can have a `pending_reveal_card_id` that pops a `New card`
  dialog over every page; the script dismisses it once after login so the pages underneath are
  reviewable. (To inspect the reveal itself, set the user's `pending_reveal_card_id` and run a
  one-off with Playwright's `reducedMotion: 'reduce'` to jump straight to its opened state.)

## Notes

- Defaults to the **local** app. Driving production (`temari.caffeinecommit.my.id`) needs real
  Strava auth — out of scope here.
- This sweeps **pages**. Interactive states (the avatar logout menu, the card-reveal CTAs, equipping
  an accessory) aren't auto-driven — spot-check those with a short one-off Playwright script that
  clicks the element, screenshots, and asserts its `boundingBox()` is within the viewport.
- Scripts: `lib.mjs` (shared: viewports, login, route discovery), `shoot.mjs` (screenshots),
  `audit.mjs` (overflow), `contrast.mjs` (rendered contrast, per ground), `mounts.mjs` (what a panel
  is actually mounted on), `light-islands.mjs` (surfaces wearing the wrong ground), `edges.mjs`
  (borders and rings that are not there), `setup.sh` / `teardown.sh`.
