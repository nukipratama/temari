---
name: browser-review
description: Drive a real browser to screenshot every user-facing page across a mobile/tablet/desktop/wide viewport matrix, capture console errors, and audit for horizontal overflow — an end-to-end visual UI review. Use when asked to "browser review", "screenshot every page", "mobile UI review", "check the UI on mobile/tablet", "full browser check", or "review the app end to end" in this repo.
---

# browser-review

End-to-end visual review: log in as the demo user, **discover every page from the route table**,
screenshot each across the viewport matrix, collect JS/console errors, and flag any horizontal overflow.
Then read the PNGs back to spot layout bugs. Everything runs **inside the Sail `app` container**
(no host browser needed), so the page list is never hardcoded — it comes from
`php artisan route:list` each run and auto-includes new pages.

## Viewport matrix (default)

Both sides of the Tailwind `lg` (1024px) breakpoint are covered on purpose — that's where the app
swaps its whole nav chrome (desktop `TopNav` ↔ `MobileTopBar` + `MobileBottomNav`):

| key | size | nav shown | in default sweep? |
|-----|------|-----------|--------------------|
| `mobile`  | 390×844  (iPhone 13)   | mobile (top bar + bottom nav) | yes |
| `se`      | 320×568  (iPhone SE)   | **still mobile** (320 < 1024) | yes — narrowest real device, catches width-driven bugs `mobile` misses |
| `tablet`  | 834×1112 (iPad portrait) | **still mobile** (834 < 1024) | no — same nav chrome as `mobile`, opt in explicitly |
| `desktop` | 1280×800               | desktop `TopNav` | yes |
| `wide`    | 1536×864 (`2xl`)       | desktop, widest `max-w-page-2xl` layout | yes |

Default is `mobile,se,desktop,wide` — `tablet` renders identical nav chrome to `mobile` (both below
the `lg` breakpoint) *and* is wide enough that it rarely disagrees with `mobile` on layout, so it's
dropped to keep the default sweep cheaper. `se`, in contrast, is kept in the default despite sharing
`mobile`'s nav chrome too: its narrower 320px width has caught real overflow that 390px missed
entirely — a CSS grid track sized to its widest child instead of shrinking to fit, a fluid font clamp
whose floor was tuned for a wider column and silently ellipsis-truncated real values. Those are
width-driven bugs, not breakpoint-driven ones, so they don't reproduce at 390px. Narrow further with
`VIEWPORTS=mobile` (or `mobile,wide`, etc.), or opt into the full five-way matrix with
`VIEWPORTS=mobile,se,tablet,desktop,wide` when tablet-specific coverage also matters (e.g. right
before a release).

## Prerequisites

```bash
./vendor/bin/sail up -d
./vendor/bin/sail npm run build               # fresh built assets — stale/missing build = Vite manifest errors or old UI
./vendor/bin/sail artisan demo:seed          # demo user + ~126 runs, deterministic
# .env must have DEMO_LOGIN_ENABLED=true (the scripts log in via the /login demo button)
```

The app is reachable **inside the container at `http://localhost`** (host-forwarded port is
`APP_PORT=7001`, but the scripts run in the container, so use `localhost`).

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

# 2. screenshots across the viewport matrix (default mobile,se,desktop,wide — see Viewport matrix above)
./vendor/bin/sail exec app node .claude/skills/browser-review/scripts/shoot.mjs
#    e.g. just phone:    VIEWPORTS=mobile ./vendor/bin/sail exec -e VIEWPORTS=mobile app node .../shoot.mjs
#    e.g. full 5-way:    VIEWPORTS=mobile,se,tablet,desktop,wide ./vendor/bin/sail exec -e VIEWPORTS=mobile,se,tablet,desktop,wide app node .../shoot.mjs

# 3. horizontal-overflow audit across the matrix (run BEFORE Inspect — its output gates which
#    pages get the expensive vision read, see "Inspect in parallel" below)
./vendor/bin/sail exec app node .claude/skills/browser-review/scripts/audit.mjs

# 4. teardown (restore node_modules; screenshots are kept as history)
./vendor/bin/sail exec app sh .claude/skills/browser-review/scripts/teardown.sh
```

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

Pass the batch dir, the viewports you shot, and the parsed `AUDIT` lines as `args`, e.g.:
```json
{
  "dir": "storage/app/browser-review/2026-06-19/143022",
  "viewports": ["mobile", "wide"],
  "pages": {
    "mobile": [{ "name": "hari-ini", "overflow": false }, { "name": "aktivitas-detail", "overflow": true }],
    "wide":   [{ "name": "hari-ini", "overflow": false }, { "name": "aktivitas-detail", "overflow": false }]
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
  mobile:  { size: '390x844',  nav: 'mobile nav (top bar + bottom nav)' },
  se:      { size: '320x568',  nav: 'still mobile nav (320 < 1024 lg breakpoint), narrowest real device' },
  tablet:  { size: '834x1112', nav: 'still mobile nav (834 < 1024 lg breakpoint)' },
  desktop: { size: '1280x800', nav: 'desktop TopNav' },
  wide:    { size: '1536x864', nav: 'desktop TopNav, widest max-w-page-2xl layout' },
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
      `intentional overflow-x-auto. Return only pages with a real, describable issue.`,
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
      `artifact, sparse demo-data grids, and intentional overflow-x-auto. Return only flagged pages.`,
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
  (e.g. `/aktivitas/{activity}` → `/aktivitas/126`). If a detail page can't be sampled, the data is
  thin — **re-run `./vendor/bin/sail artisan demo:seed`** and try again.
- **Redirect dedupe:** pages reached via a 301 alias are screenshotted once (keyed by the landed URL).
- **Card-reveal modal:** the demo user can have a `pending_reveal_card_id` that pops a `Kartu baru`
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
  `audit.mjs` (overflow), `setup.sh` / `teardown.sh`.
