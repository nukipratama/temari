# v1 polish — running TODO

Working tracker for the post-parity phase. The parity program itself is finished and its
`plan/` tree has been retired to git history; this file is all that remains, and holds the
items raised one at a time since, so none of them get lost between sessions.

**Status key:** `todo` · `doing` · `done` · `cut` · `needs decision`

| # | item | raised | status | notes |
|---|---|---|---|---|
| 1 | Full browser review across every page + edge cases | 2026-09-03 | done | Swept 15 pages × 4 viewports + 6 pages the default sweep never reaches. Findings below. |
| 2 | Profile stat rail clips content, strands half the panel on wide | 2026-09-03 | done | Measured: `Threshold` value needed 81px and its label 85px inside a 68px box; rail was 978px holding 492px of tiles. Fixed in [ProfileHero.tsx:114](../resources/js/components/profile/ProfileHero.tsx#L114) — uniform 178px on desktop, 108px on mobile, no clipping at any width. |
| 3 | Confirm PHP 8.5, not 8.4 | 2026-09-03 | done | Already 8.5 everywhere — `composer.json ^8.5`, container `8.5.8`, `Dockerfile` `frankenphp:1.12.4-php8.5-alpine`, CI `PHP_VERSION: '8.5'`. The `8.4` hits were MySQL. No change needed. |
| 4 | Pace-targets rail: end labels spill outside the card | 2026-09-03 | done | Every marker was `left:X%` + `-translate-x-1/2`, so the 0% and 100% labels hung half outside. Split dot from label in [PaceTargetsCard.tsx](../resources/js/components/profile/PaceTargetsCard.tsx); dots keep their exact value position, labels shift by their own offset. Regression test added. |
| 5 | Skip the devtools basic-auth gate outside production | 2026-09-03 | done | [EnsureDevtoolsAccess.php:17](../app/Http/Middleware/EnsureDevtoolsAccess.php#L17). `APP_ENV` defaults to `production`, so the skip fails safe. Gate tests now arm production explicitly and were **proven non-vacuous** (neutering the middleware turned all 10 red). |
| 6 | Put every devtools surface under a `/devtools` prefix | 2026-09-03 | done | `/ai-usage` → `/devtools/ai-usage` (+2 POST routes, renamed `devtools.*`), `PULSE_PATH` → `devtools/pulse`, `HORIZON_PATH` → `devtools/horizon`. 54 references rewritten. Two dead links on the hub (`/horizon`, `/pulse`) and one live Pulse blade link fixed. Old paths now 404, all five new paths 200. |
| 7 | Devtools UI/UX rework | 2026-09-03 | done | Nav fixed on all four surfaces (design page had no header at all; ai-usage's breadcrumb was an inert span; Pulse had nothing). Design page: denser swatch grid + audit collapsed to failures, 14,660px → 13,301px. Pulse re-themed to follow the app's ground, 48 fixed-ground tokens swapped, 7 contrast failures → 0. **Two carve-outs**, both recorded below: the D1 grounds split (deferred to item 13) and Pulse's missing scroll affordance. |
| 8 | Sweep for refactor opportunities | 2026-09-03 | folded into 13 | Scope overlapped the audit almost entirely, so refactor findings come back as one bucket of item 13 rather than a separate pass over the same files. |
| 9 | Dead-code sweep | 2026-09-03 | done | 3 PHP classes, 6 dead TS exports, 3 dead grounds removed; 8 over-exported symbols made private. Everything else came back clean. Detail below. |
| 10 | Stale-doc sweep | 2026-09-03 | done | Fixed a wrong route name, stale `/pulse` and `/horizon` paths across 5 docs, and 3 unqualified basic-auth claims. The parity-cut docs turned out to be fine. 7 ADRs carry stale paths and need your call. |
| 11 | Clean up `plan/` and prototype leftovers | 2026-09-03 | done | 79 files deleted; only `todo.md` remains. `resources/brand/prototype/` was already gone. Three live inbound references reworded (`CLAUDE.md`, `docs/features/dashboard.md`, `scripts/check-raw-palette.mjs`); two immutable ADRs still cite retired paths, flagged rather than edited. |
| 12 | Pest TIA | 2026-09-03 | done | Discussed and acted on. Found and closed a live blind spot, added `TiaWatchMapTest`, corrected a wrong reason in `ci.yml`, and built the baseline workflow. Detail below. |
| 13 | Whole-codebase audit: security, performance, clean code | 2026-09-03 | done | 5 parallel read-only agents; every finding verified before acting. 4 real bugs fixed, 3 agent claims failed verification, 4 items need your call. Detail below. |

---

## Decisions taken 2026-09-03

| finding | ruling |
|---|---|
| D5 Pulse | **Fix both bugs, add the back-link, and re-theme to the dark ground.** Un-force light mode and map Pulse's CSS variables onto the app palette. |
| Delivery | **Keep accumulating into one PR.** No intermediate PR; everything lands together. |
| D1 grounds split | **Deferred to item 13**, the whole-codebase audit. `contrast.mjs` guards the real risk meanwhile, so this is a quality refactor rather than a bug fix. |
| Side findings | **All four in scope**: verify the `bg-cream` modals, drop the 3 dead grounds, review `/onboarding`, check the duplicate Q&A answers. |
| N1 week volume | **Relabel the Plan header as a season target.** Wording only; the season-nominal figure stays. |
| D4 design page | **Denser per-family swatch grid + audit table collapsed to failures by default**, on top of the D1 ground fix. |
| D2 / N2 / D3 / N5 | **All four in scope**: TRIMP clip, scroll-rail affordance (edge fade), devtools back-links, login wide-screen dead space. |
| N3 wordmark | Not raised for change — stays hidden below 380px. |
| N4 duplicate answers | Still open; needs a live ask to classify. |
| Order of remaining work | **#9 dead code + #10 stale docs as one pass**, then #11 `plan/`, then #8 refactor, then #13 audit. |
| Sweep scope | **All four categories**: unreferenced PHP classes and TS modules, unused exports and dead branches, unused dependencies, orphaned tokens and assets. |
| `plan/` cleanup | **Delete the whole tree except `todo.md`.** Git history is the record. |

---

## Findings

Everything below was verified against the running app (DOM measurement or a DB query), not read
off a screenshot. Where a review agent's claim did not survive that check, the correction is noted.

### Clear bugs — fixed already

- **F1 · Profile stat rail** — see item 2.
- **F2 · Pace-targets rail** — see item 4.
- **F3 · Two dead links on the devtools hub.** `/horizon` and `/pulse` 404'd after the prefix move;
  the hub was the only place linking to them. Fixed with the move.

### Clear bugs — awaiting your go-ahead

- **D1 · The design page's contrast audit is ground-blind, and it is the app's own health check.**
  It scores `paper` (light-ground) token pairings but reads whatever CSS variables are currently
  applied. On the **default dark ground** that is 67 false failures; on light the same audit passes.
  Proven by running it under both grounds:

  | ground | result |
  |---|---|
  | dark (default) | **20/87 pass, 67 fail** |
  | light | 84/87 pass, 3 fail |

  So the "67 failing contrast pairs" is an artifact, not a design-system regression — but the page
  exists to surface exactly this, and right now it cries wolf on first load. It needs to score the
  ground it is actually rendering (or score both side by side). The **3 genuine failures on light**
  are the ones worth triaging.

- **D2 · `TRIMP` label clipped at 320px.** On the activity detail page the tile is a 35px box holding
  40px of text under `overflow:hidden` — genuinely cut, not ellipsised gracefully. Only at 320px.

### Rendered-contrast audit — three real bugs, now fixed

The design page's token audit was so noisy it was useless, so I wrote a **rendered** audit instead
and kept it: [.claude/skills/browser-review/scripts/contrast.mjs](../.claude/skills/browser-review/scripts/contrast.mjs),
run per ground. It walks every element that actually paints text on every page, resolves its true
background, and scores it against the WCAG minimum for its computed size and weight. That found what
the token audit could not.

| page | text | ratio | cause | fix |
|---|---|---|---|---|
| activity | "easy" mood chip | **1.13** | `MoodChip` painted `text-foreground` on the mood's *fixed* pastel fill. `foreground` flips near-white on dark; `mood-*-bg` does not flip. | Pin to `text-mood-{key}-ink` via a new `MOOD_INK` map. |
| history | "Drift 11.2%" | **1.64** | The alert chip used `text-mood-gassed-ink`, a fixed dark red, over a tint on a dark card. | Switch to `bg-ember/15 text-ember-ink` — `ember-ink` *does* flip (`#9b3245` → `#ce808e`). |
| profile | phase labels | **4.25** | `text-text-2` on `bg-border-strong`, just under the 4.5 AA floor at 13px. | `text-foreground`. |

**Result: dark ground went from 4 failures to 1, and that one is a false positive** (the "base" phase
pill sits on a lime `repeating-linear-gradient`, which has no `background-color` for the resolver to
read). Light ground has 1, also a false positive (a map-tile backdrop).

**The root cause is worth remembering**: the `mood-*` family is fixed identity and does **not** flip
per ground, so any text on a mood fill must be pinned to that family's own `-ink`. Guarded now by a
test in `MoodChip.test.tsx` that asserts the pinned ink and rejects `text-foreground`.

One claim I made and then disproved myself: `StravaSyncButton` paints `bg-cream` with `text-text-2`,
which *looked* like the same bug. The rendered audit cleared it — it never renders on the swept pages
in the demo user's state. Worth a look if that button's state is ever reachable.

### Needs your decision

- **N1 · One week, three different volume numbers.** For Mon 31 Aug – Sun 6 Sep the app shows
  "39.1 km this week" on the dashboard, "65 km · 5 sessions" on the Plan page's Wk1 header, and a
  day list beneath that header summing to 33.3 km. Traced: **no arithmetic bug** — three independent
  pipelines, each internally correct:

  | number | source | redistribution | reflects live edits |
  |---|---|---|---|
  | 39.1 dashboard | `CurrentWeekPlanBuilder` | no | yes |
  | 65 Plan header | `SeasonSummaryBuilder` | no | **no** (deterministic season schedule) |
  | 33.3 Plan rows | `PlanRenderer` + `VolumeRedistributor` | **yes** | yes |

  The header/rows gap is a self-acknowledged trade-off at
  [SeasonSummaryBuilder.php:31-34](../app/Services/Run/Plan/SeasonSummaryBuilder.php#L31-L34). The
  problem is that all three wear near-identical labels, so a header sits directly above a day list
  that legitimately sums to something else. **Options:** (a) relabel the header as a season target,
  (b) make the header sum its own rows, (c) leave it. I lean (a) — it is a wording fix, and (b)
  would throw away the season-nominal figure the page is built around.

- **N2 · Scroll rails have no affordance.** The profile stat rail and the activity laps rail are
  `overflow-x:auto` with the scrollbar deliberately hidden. Content is reachable by swiping, so this
  is **not** the data loss a review flagged — but nothing hints there is more to the right. The laps
  rail shows 390px of 850px even on a normal phone. Options: edge fade, a peeking partial tile, or
  leave as-is.

- **N3 · Wordmark disappears below 380px.** Confirmed deliberate: `hidden min-[380px]:block`, so at
  320px the header shows an unlabelled logo mark. Intentional, but worth confirming you still want it.

- **N4 · Two starter questions on the activity page render an identical answer.** "how did I finish
  faster than I started?" and "which km cost me the most?" both show the same sentence. Needs a live
  ask to tell a canned preview from a generation bug — say the word and I will drive it.

- **N5 · Login's "what you get" row strands space on wide.** The row above it fills three columns;
  this one fills about half the width at 1536px.

### D1, precisely — what the design page's audit actually needs

I started the ground fix and found it is bigger than "a bug", so it is specified here rather than
half-done. Read straight off [app.css:648](../resources/css/app.css#L648), which is the whole truth:

**`[data-theme='dark']` redefines exactly these** — `background`, `foreground`, `card`, `popover`,
`secondary`, `muted`, `accent` (+ their `-foreground`s), `border`, `input`, `icon-accent`, `text-2`,
`text-3`, `border-strong`, `today-accent`, `btn-primary-*`, and the `-ink` family
(`horizon`/`leaf`/`ember`/`citrus`/`rarity-*`).

**It does not redefine** — `cream`, `cream-deep`, `surface*`, `ink`, `ink-2`, `ink-3`, `line`,
`line-strong`, or any `mood-*`.

So `grounds.json`'s single `paper` list mixes two incompatible kinds, and `PAIRS` in
[designTokens.ts:348](../resources/js/lib/designTokens.ts#L348) pairs the **fixed** ink tier with
**all** of them. That is the entire source of the noise:

| ground kind | members | text tier that lands on it | correct on both grounds? |
|---|---|---|---|
| reactive | accent, background, card, muted, popover, secondary | `foreground` / `text-2` / `text-3` / `border` | yes, both halves flip together |
| fixed light | cream, cream-deep, surface* | `ink` / `ink-2` / `ink-3` / `line` | yes, neither half flips |

**The fix is to split the list and pair each kind with its own tier.** Blast radius is the reason it
is not done yet: `grounds.json`, `designTokens.ts`, `grounds.mjs`, the 674-line
`DesignTokenContrastTest.php` (which mirrors the same logic), and the classification test that
requires every painted `bg-*` to be in a known kind.

Two things found on the way, worth their own line:

- **`surface-card`, `surface-elev` and `surface-warm` are classified grounds that nothing paints**
  (0 call sites each). Dead entries — a candidate for the dead-code sweep.
- **`bg-cream` modals are unverified.** `ShareCardModal` and `TemariNudgeModal` paint fixed-light
  `bg-cream`, and no page sweep opens them, so `contrast.mjs` never scored them. If either uses a
  reactive text token it is unreadable on the dark ground, exactly like the MoodChip bug. Worth
  driving open once.

### Side findings — all four resolved

- **Dead grounds dropped.** `surface-card`, `surface-elev` and `surface-warm` had zero paint sites
  and are gone from `grounds.json`. The tokens themselves stay (`--color-surface-elev` is still used
  in a gradient at [app.css:475](../resources/css/app.css#L475)); only the ground classification went.
  **Proven the guard still fires** — a probe component painting `bg-surface-card` turned
  `DesignTokenContrastTest` red, and removing it turned it green again.

- **The `bg-cream` modals were badly broken, and it was worse than predicted.** Driving
  `ShareCardModal` open on the dark ground measured **7 contrast failures, 3 of them at ratio 1.00** —
  literally invisible, including the "Copy image" primary action. Cause: the modal chrome painted
  fixed-light `bg-cream`/`bg-cream-deep` while its text came from the ground-reactive tier, so on dark
  it was near-white on near-white. Fixed by making the chrome ground-reactive (`bg-card` / `bg-muted` /
  `border-border`) rather than pinning text at each site, because the failures came from *shared*
  primitives. That surfaced a second, wider bug: **`toggleButtonVariants` paired a fixed `cream-deep`
  fill with reactive text**, so it was broken on dark everywhere it is used (`ShareCardModal` and
  `UsageFilters`). Both states are ground-reactive now. **Share modal: 7 failures → 0, on both grounds.**

- **`/onboarding` reviewed at last.** Captured by nulling the demo user's `onboarded_at`, then
  restoring it exactly. Renders clean on both viewports: no horizontal overflow, no console errors,
  zero contrast failures.

- **The duplicate Q&A answers are a demo-seed bug, not an LLM one.** Two different questions had
  byte-identical stored answers because [DemoRunSeeder.php:608](../database/seeders/Demo/DemoRunSeeder.php#L608)
  wrote one hardcoded string for every topic, ignoring `$topic`. Each of the nine
  `RunQuestionTopic` cases now gets its own answer built from the run's real distance and pace. The
  LLM path was never at fault.

### A silent class-merge bug, found while fixing the wide-screen items

The onboarding review claimed the desktop Continue button stretched to ~950px. Reading the code I
said that was wrong, because `PageContainer` is passed `min-[900px]:max-w-[520px]`. **Measuring
proved the reviewer right and me wrong: the button was 982px.**

Cause: `max-w-column` / `max-w-column-wide` (the `--container-*` widths) were not registered in any
tailwind-merge class group, so `cn()` kept *both* sides of the conflict and CSS source order decided
— the named utility won and the caller's narrower cap did nothing. This is exactly the `cn.ts`
hazard the plan flagged: a merge that fails silently, with no failing test.

Registered them in a `max-w` group in [cn.ts](../resources/js/lib/cn.ts) with three regression tests.
The button is now 462px. Onboarding was the only caller passing a width, so the blast radius was one
page, but the bug was general.

Also fixed: **login's "what you get" row** used `inline-grid` with `mx-auto`, which does not centre an
inline box, so it sat left with a gap to its right. Now a filling `grid`, matching the three-column
row above it. Both rows measure 982px.

### Devtools, specifically (item 7)

- **D3 · Navigation is one-directional.** The hub links out to all four; `/devtools/design` has no
  header or back-link at all, `/devtools/ai-usage`'s `TEMARI · DEVTOOLS` is a styled but inert
  `<span>`, and `/devtools/pulse` + `/devtools/horizon` have nothing. Cheap fix, big improvement.
- **D4 · The design page is 14,660px on desktop and 19,280px on mobile** — past Chromium's screenshot
  cap, which is why no mobile capture of it exists. One swatch per token plus an 87-row audit table
  rendered as stacked divs. Wants a denser grid and a collapsed/fail-only audit table.
- **D5 · Pulse — done, and the diagnosis changed twice on the way.** It now follows the app's own
  ground instead of force-loading light: one script drives Pulse's `dark` class *and* `data-theme`,
  so its chrome and the first-party cards (which read `--color-*`) agree. The blades were written for
  light Pulse and used fixed light-ground tokens, so 48 of them were swapped to the reactive tier
  (`text-ink`→`text-foreground`, `text-ink-3`→`text-text-3`, `bg-surface-sunken`→`bg-muted`), and the
  existing grey-override hack gained a `.dark` half because Pulse's own dark steps are too dark to
  read on its gray-900 card. A back-link was added — Pulse carries none of the app's chrome.
  **Measured: 7 contrast failures on Pulse (one at ratio 1.00) → 0, on both grounds.**

  Two corrections to the review that found it:

  - **The "near-blank stock cards" were not a CSS collision.** Queues / Exceptions / Slow Requests
    render `No results` because this machine has no queue, exception or slow-request data. The text
    is present and legible. Nothing to fix.
  - **The clipped Strava budget block is not Strava-specific.** *Every* first-party card overflows
    its 269px inner scroller (AI Pipeline +272, Strava +167, Self-Heal +102, Notification +92,
    System Control +47, Scheduler +5). It is Pulse's fixed-height-card-with-internal-scroll design,
    so nothing is lost, but there is no scroll affordance. **Raising `rows` does not work** — the
    cards measure 384px whether they span 2, 3 or 4 rows, so that change was reverted rather than
    shipped as a no-op. Left open below.

  While verifying this I found **a bug I had introduced myself**: the earlier `/ai-usage` rename left
  `VENDOR_PAGES` in the browser-review skill pointing at `/pulse`, which now 404s. The audit was
  therefore scoring an error page and reporting a hollow "0". Fixed, and only then did the real 7
  failures appear.

### Verified NOT bugs

- Profile stat tiles and lap tiles "clipped with no way to see them" at 320px — they scroll (see N2).
- The `ProfileHero` 56px overflow — that is the decorative `-right-14` glow under `overflow-hidden`.
- Zero horizontal page overflow on any page at any of the four viewports.
- Full PHP suite green: **3610 passing** after the route move and gate change.

### Gap in the review

`/onboarding` redirects to `/login` for a guest and to `/` for the seeded demo user, so it was never
captured — 1 of the 11 prototype screens is still unreviewed. Reviewing it means seeding a user who
has not onboarded. Say the word and I will.


---

## Item 12 — Pest TIA, what the discussion produced

**Measured first.** TIA's payoff is inverse to diff size: nothing-changed replays in **5.6s** against
a ~45s full run, but a 74-file diff (340 affected test files) took **56s** — no saving at all. Test
count stays 3612 either way, because unaffected tests are replayed rather than skipped.

**A live blind spot, found and closed.** TIA maps tests to code via pcov edges, so a test that *reads*
files instead of executing them records no edge and silently stops running. Six of the seven
filesystem-scanning tests live in `tests/Unit/Architecture`, which the watch map covered wholesale.
The seventh did not: `NarratorsCoverageTest` globs `Services/AI/Narrators` and `Agent/Tools`.

Proven, not assumed. A realistic new narrator whose prompt names a tool it does not carry:

| run | before the fix | after |
|---|---|---|
| `--no-tia` (what CI does) | **failed** | failed |
| normal local run (TIA on) | **passed** — false green | **failed** |

**`TiaWatchMapTest`** now guards the map, deriving it from `tests/Pest.php` rather than restating it,
the way `CiPathFilterTest` reads `ci.yml`. Two assertions, both proven non-vacuous: removing the
narrator entries names `NarratorsCoverageTest`; pointing a target at a deleted path names it.

**The `ci.yml` comment was wrong and is corrected.** It claimed a narrowed run "would silently shrink
the coverage gate". [Pest's docs](https://pestphp.com/docs/tia) say a replay reconstructs coverage
faithfully, `--min` included — so that reasoning was false, even though the conclusion was right.
The real reason is measured: `--tia --coverage` **cannot run on this suite at all**, throwing
`InvalidCoverageDataException` against an existing graph and exhausting a 512M `memory_limit` in
`CoverageMerger` on an ~80MB graph with `--fresh`. Pest's own guidance agrees CI should stay exhaustive.

**Baseline sharing, built.** [tia-baseline.yml](../.github/workflows/tia-baseline.yml) records the
graph on push to main plus nightly and publishes it as `pest-tia-baseline`; `baselined()` in
`tests/Pest.php` pulls it via `gh`, which is now in the dev image (`github-cli`, Alpine, dev stage
only — verified by rebuild: `gh 2.97.0`). `GH_TOKEN` is passed through `compose.yaml` and documented
in the example env. It runs on `ubuntu-latest`, never the homelab runner. Unset token degrades
silently — verified: a local run with `baselined()` and no auth still passed 3612.

**Research, not invention.** Pest offers no "always-run" marker, so `watch()` is the only lever; the
industry framing is the same (a source-only dependency map is blind where file-driven behaviour lives,
so you either track those inputs or force those tests to run).

**Still unverified:** the artifact round trip. No `pest-tia-baseline` exists until the workflow runs on
`main`, and `gh` in the container is unauthenticated until `GH_TOKEN` is set. Both fail soft.

**Corrected twice along the way:** a stored note of mine said local coverage was impossible (`pcov`
ships in the dev image, so it is not) and then said `--no-tia` was needed because coverage would read
low (it is needed because the run crashes). Also self-inflicted: I deleted `vendor/pestphp/pest/.temp`
to clear a corrupt graph and broke Pest until the directory was recreated.


---

## Item 9 — dead-code sweep, what it found

Scanned four categories against the whole tree. **Most of it came back clean**, which is the useful
result: the 1:1 test convention and the token guards have kept things tight.

### Removed

| what | where | why it was dead |
|---|---|---|
| `GenerateStoryLineJob` | `app/Jobs/Story/` | A wrapper around `Temari`, dispatched by nothing. `Temari` itself is alive (`ActivityPipeline`, `DashboardController`, `RunController`) and writes story lines directly. |
| `WeeklyRecapBuilder` | `app/Services/Gamification/` | Superseded — `WeeklyRecapNarrator` builds its context from `WeekTotalsTool` instead. |
| `WeeklyRecap` | `app/Services/Gamification/` | The builder's DTO. Its only importer was the structure test's exemption list. |
| `FIDGET_PATTERNS`, `FidgetPattern`, `idleByMood`, `breath` | `lib/motion.ts` | Mascot rig animation, orphaned when `PP2` replaced the rig with `FaceIcon`. `useIdleFidget`, named in their own comment, no longer exists. |
| `RARITY_DOT` | `lib/runcard.ts` | A duplicate of the live `rarityVariants` in `lib/variants.ts`. |
| `monthsSinceId` | `lib/pace.ts` | No caller anywhere, including tests. |
| `surface-card`, `surface-elev`, `surface-warm` | `grounds.json` | Classified as grounds but painted nowhere. The tokens stay — `--color-surface-elev` is still used in a gradient. |

**Eight more symbols were over-exported, not dead** — used only inside their own file, so they lost
the `export` keyword rather than the code: `HR_SIGNAL_BPM`, `PACE_SIGNAL_SEC`,
`IMPOSSIBLE_PACE_SEC_PER_KM`, `PREFERENCES_SUB_STEPS`, `TREND_RANGES`, `buildCardStats`,
`paceShapeFromDetail`.

### Came back clean

- **Unimported TS modules: 0** of 219, under a strict import resolver.
- **Unused npm packages: 0.** Ten were flagged and every one proved alive — the eslint plugins are
  imported by `eslint.config.js` (my scan looked for `.mjs`), `@vitest/coverage-v8` backs
  `provider: 'v8'`, `concurrently` runs from a composer script, and `@types/*` are ambient.
- **Unused composer packages: 0** of 25.
- **Orphaned `--color-*` tokens: 0** of 89.
- **Accessory SVGs: already gone**, so `W2` did its job.

### Deliberately not touched

- **Artisan commands.** Twelve have no class-name reference, because Laravel auto-discovers them and
  they are invoked by signature. They are hand-run ops tools, not dead code.
- **Named routes.** Twenty-one are never passed to `route()`, but route names are the canonical
  Laravel identifier and the helper *is* used (29 calls plus 9 `redirect()->route`). Stripping them
  would be churn with breakage risk and no benefit.
- **`ActivityPolicy`** looked dead to the scanner and is load-bearing:
  [RunController.php:44](../app/Http/Controllers/RunController.php#L44) calls
  `$user->can('view', $activity)`, which Laravel resolves to it by naming convention. Deleting it
  would have silently broken authorization on the run detail page.

**The lesson worth keeping:** the 1:1 test convention *hides* dead code. Every one of the three
deleted PHP classes still had a passing test, so the suite was green and the structure gate was
satisfied while nothing in the app used them. "Has a test" is not evidence of "is used".


---

## Item 10 — stale-doc sweep

**The hypothesis was wrong, which is the useful part.** I expected whole feature docs to describe
cut features. They do not: `cards-collection.md`, `records.md`, `targets-accessories.md` and
`temari-mascot.md` were all updated during the parity program and describe the cuts in the past
tense ("the wardrobe that wore them is gone", "since PP2 retired the mascot rig"). A scan that
flagged them for *mentioning* removed class names was reading history as staleness.

The real staleness was created **today**, by my own route move:

| fixed | was | why it mattered |
|---|---|---|
| `route('devtools.ai-usage')` | `route('ai-usage')` | The doc named a route that no longer exists — copying it would throw `RouteNotFoundException`. |
| `/devtools/pulse`, `/devtools/horizon` | `/pulse`, `/horizon` | My earlier rename rewrote `/ai-usage` only, so `deployment.md` ended up with one updated path beside two stale ones in the same sentence. |
| "gated in production by HTTP Basic Auth" | "gated by HTTP Basic Auth" | Three docs stated the gate unconditionally. Since the gate now skips outside production, the blanket claim was wrong for every non-prod reader. |

Clean elsewhere: **no** references to the three deleted classes, **no** `living` doc describing a
surface that no longer exists, and **no** broken wikilinks across ~140 targets.

**My bulk regex over-reached twice** and both were caught rather than shipped: it rewrote file paths
(`config/pulse.php` → `config/devtools/pulse.php`), which `check-doc-citations.php` turned red, and
compose service names (`scheduler/horizon/pulse`), which I caught on a read-back. Worth remembering
that a path-rewriting regex hits URLs, file paths and service names alike.

### Resolved — 9 immutable ADRs given dated banners

Ruled 2026-09-03: a dated banner may correct a fact that changed, while the decision and its
reasoning stay untouched. Each got one, in the style `ink-grounds-derived-not-listed.md` already
used. Original bodies are unedited.

| ADR | stale reference |
|---|---|
| `pause-reason-derives-from-the-dispatch-gate.md` | `/pulse` ×3 |
| `scoped-run-qa-not-an-analysis-row.md` | `/ai-usage` |
| `narration-agents-on-openai-php.md` | `/ai-usage` |
| `cost-ceiling-answers-run-questions-rule-based.md` | `/ai-usage` |
| `cost-ceiling-degrades-to-rule-based.md` | `/ai-usage` |
| `bounded-self-heal-and-dead-letter.md` | `/ai-usage` |
| `chained-narration.md` | `/ai-usage` |

Two more cited the retired `plan/` tree — `demo-notifications-are-inbox-only.md` got its own
banner, and `ink-grounds-derived-not-listed.md` had its existing 2026-08-31 banner extended rather
than a second one stacked on top.

**Brand SVGs: kept.** `temari-mark-small`, `temari-mark-mono` and `temari-lockup` are referenced by
nothing, but they are brand-kit variants rather than code — the kind of asset you want the day you
need a README header or a social card. Being unreferenced is normal for design source.


---

## Item 13 — the audit

Five read-only agents (security, performance, backend architecture, frontend, duplication/tests).
**Every finding was verified against the code before acting**, which mattered: three did not survive.

### Fixed — real bugs

| # | bug | evidence |
|---|---|---|
| 1 | **`docker/Caddyfile`'s `@ops` CSP matcher still pointed at `/horizon` and `/pulse`** — a bug **I introduced today** with the route move. Both dashboards would have fallen through to the strict app-wide CSP, which lacks the `unsafe-eval` Horizon's Vue templates and Pulse's Alpine need. Not a hole (it is *stricter*), but it would have broken their UI in prod. | `docker/Caddyfile:71` vs `config/horizon.php:45`, `config/pulse.php:43` |
| 2 | **The Telegram bot token leaked into logs and the database.** The token sits in the URL *path*, and Guzzle's redaction only covers `user:pass@host` — so any transport failure (a homelab DNS blip) put the live token into `ConnectionException`'s message, which is logged by `MaintainerAlerter`, persisted on the delivery-claim row, and lands in `failed_jobs`. Traced through Guzzle and Laravel source, not assumed. | `app/Services/Telegram/TelegramClient.php:110-117` |
| 3 | **`FormStatus::label()` returned Title Case**, breaking rule **P37** (UI chrome is lowercase, a fixed rule since 2026-09-01). Its own comment claimed it mirrored `lib/formStatus.ts`, which was already lowercase — and its test pinned the wrong strings, so the test was protecting the bug. | `app/Services/Run/Story/FormStatus.php:16-24` |
| 4 | **An impossible `=== null` guard** on a `NOT NULL` column — dead defensive code the project's own rules discourage. Dropped, and with it a `phpstan-baseline.neon` entry (3 → 2). | `app/Services/Run/Plan/ProgressionSeriesBuilder.php` |

Both #2 and #3 shipped with a regression test, and #2's was **proven non-vacuous** — removing the
redaction turns it red.

### Did not survive verification

- **"Rarity hex is duplicated across three files with nothing enforcing it."** `DesignTokenMirrorsTest`
  already pins `app/Enums/Rarity.php` *and* `lib/runcard.ts` to `app.css`. Proven by nudging one hex
  and watching the suite go red. The agent had checked `DesignTokenContrastTest`, the wrong test.
- **"`TokenUsageController` reimplements the open-recap-period rule and can drift from
  `AnalysisService`."** Both call the same `RecapPeriod::lastClosedMonth()` / `lastClosedWeekEnding()`,
  so the rule is already single-source. The controller's separate shape exists to batch a collection
  instead of querying per row.
- **"`story_lines_mood_filter_idx` is a dead index."** True that nothing queries it, but the table is
  128 rows / 80 KB. No measurable cost; not worth a migration.

### Clean, with evidence

Security: no IDOR (every owner-scoped route checked, 404-not-403 throughout), no injection (every
`whereRaw`/`selectRaw` uses bound parameters), no SSRF (push endpoints allowlisted, weather/geo hit
fixed hosts), webhooks use `hash_equals`, no mass assignment, open-redirect sanitiser solid. The
devtools-gate change is safe: `compose.prod.yaml` hard-sets `APP_ENV: production`, which overrides
the host env file, and the app port is loopback-only behind the tunnel. `GH_TOKEN` and `github-cli`
are dev-stage only — the production image never sees either.

Performance: no N+1 anywhere (shared props are closures, controllers batch their lookups), no
Octane-unsafe static state, indexes match query patterns, growth tables all pruned.
`activity_streams` is 92% of the DB and unpruned **by design** — it is the recalibration source of
truth and is never read on a request path.


### Ruled and done — the two the audit left to you

- **The mood heuristic is deleted, not remapped.** `lib/moodFromActivity.ts` guessed a run's mood
  from TRIMP before narration landed, and after the `#550` rename three of its six branches
  contradicted both their own comments and the canonical meanings in `Temari.php` — a solid *hard*
  session reported `easy`, a good *aerobic* run reported `blazing`, a shake-out reported
  `overloaded`. Its test titles still carried the pre-rename names (`dim`, `spinning`, `glow`,
  `bouncy`), which is why it went unnoticed. `RunListRow` now renders **no dot** until a real mood
  exists, rather than one that could be the opposite of the truth and then flip. Ruled: no
  false-hope affordance beats a wrong one.
- **`HISTORY_WEEKS` is now one constant.** `CurrentWeekPlanBuilder::HISTORY_WEEKS` is public and
  `PlanController` references it, so the two that must agree cannot drift.
  `ScoreComplianceCommand` keeps its own window, which serves a different purpose.
- **`SESSION_SECURE_COOKIE` left as-is** — HTTPS-only behind Cloudflare with correctly narrowed
  proxy headers. Accepted on reasoning; noted as unmeasured.

### Worth knowing: `composer check` runs Pest **with** TIA

Its pest step is `vendor/bin/pest --parallel` — no `--no-tia` — so the local gate is graph-narrowed
(`19 affected test files from 90 changed files` on the final run). That is sound: a replay
reconstructs pass/fail faithfully, and PHP coverage is not part of `composer check` at all. CI stays
exhaustive on `--no-tia`. Every result in this document was also confirmed by a separate full
`--no-tia --parallel` run.

---

## LLM-usage grill (2026-09-04)

Design tree fully mapped via `/mattpocock-skills:grilling`. Four workstreams, one pass,
same working tree. Rulings below are settled; do not re-open without a new grill.

**Deliverable** — `docs/architecture/llm-triggers.md` keeps its filename (four docs wikilink
`[[llm-triggers]]`) but is retitled and rewritten to absorb origins, stops, a full inventory
and verdicts. Hand-written, guarded by a new bidirectional structure test over narrators,
concrete `AgentTool`s and `AnalysisType` cases. Deterministic side scoped to what a tool
actually reads. `TemariPersona` measured and ruled as its own cost line.

**Verdicts** — three-way (earns it / overpaid for it / rules could do this). Proposed by me,
ruled by the user after. Cost ranking is order-only from a structural proxy. Tools get a
flagged shortlist, not 26 rulings. Keeps and right-sizings live in the note's verdict column;
cuts get a dated ADR.

**Workstreams**
1. `pr_context` deleted entirely. **DONE** — billed once per beaten PR and rendered nowhere.
   Cut: enum case + 4 match arms, narrator, job, `PersonalRecordTool`, authorizer arm, ingest
   dispatch, `SelfHealer::resumePrContext()`, `BackfillAgeGate` arm, rule-based arm,
   `CleanupDeletedActivityJob` loop, routing key, env var, TS enum, demo seeder, 18 test files.
   No pruning migration: prod is being rebuilt, and `KnownAnalysisTypeScope` already retires a
   case safely anyway. PR celebration survives via `PostRunSpeechNarrator`'s `PersonalRecordsTool`.
   Green at 3580 PHP / 1836 frontend.
2. `origin` on `ai_token_usages` + `AnalysisOrigin` enum + `/devtools/ai-usage` filter. **DONE.**
   Origin is declared once per dispatch entry point (`NarrationOrigin::set()`), stamped onto the
   job by `AnalysisService::stamped()`, restored by the job before generating, and written beside
   `kind` by `RecordTokenUsageAction` — rather than threaded through 12 narrator signatures, which
   would push a dispatch concern into every prompt builder. `Unknown` is the default so a missed
   site reads as unattributed instead of inflating a real origin. The page gained an origin filter
   and a per-origin breakdown table.
3. Schema squash: **DROPPED 2026-09-04, do not re-propose on the old premise.** Measured:
   all 75 default migrations run in **1.78s** including PHP boot, so the whole saving is ~1.8s
   per test-process bootstrap. Against that, `MySqlSchemaState::dump()` shells out to
   `mysqldump` and **`load()` shells out to `mysql`** (vendor line 74), and neither binary is in
   the app image. A committed schema file therefore makes `mariadb-client` a **permanent**
   dependency of the dev image (CI builds a fresh DB per test process) and of the production
   image (the cutover `migrate:fresh` loads the file). Permanent package in two images, a dump
   to regenerate on every schema change, and no readable migration history, for 1.8 seconds.
4. The note + its structure test. **DONE.** `docs/architecture/llm-triggers.md` retitled and
   rewritten to cover the whole surface; `LlmInventoryDocTest` guards it bidirectionally over
   narrators, concrete agent tools and `AnalysisType` cases, with the retirement section carved out
   as the one place allowed to name a deleted class. Both directions were proven to fail before
   being trusted.

**Verdicts: all ruled 2026-09-04.**

- **Step budgets aligned.** The three plan narrators now declare `maxSteps: 4`, matching every
  other one-tool narrator. Honest framing, and the note says so: a step budget is a **ceiling, not
  consumption**. A one-tool narrator spends ~2 turns regardless, so this bounds a runaway loop
  rather than lowering a bill.
- **Plan cadence: re-narrate only what changed.** `requestForCurrentWeek()` no longer passes
  `invalidate: true` blanket. Each day/week row is stamped with a `MaterialFingerprint` of the
  material it describes (`forPlannedSession()` mirrors `PlanDayTool`, `forPlanAdaptation()` mirrors
  `PlanWeekTool`), written via a new `AnalyzeRowJob::fingerprintFor()` hook, and the Monday sweep
  invalidates only on a mismatch. A **null** fingerprint counts as changed — the inverse of the
  per-run rule, deliberately: only the rule-based paths leave it null (cost-capped or
  content-filtered), and those must stay eligible for real narration rather than keeping filler.
  Manual edits via `PlanController::update()` still re-narrate unconditionally.
  Six tests added, all proven to fail when the change is reverted.
- **Toolboxes left as they are.** `run_insight` (10 tools) and `card_flavor` (up to 6) recorded as
  *earns it*. Neither can tighten its budget while it holds them, so the only lever was removing
  tools, which flattens the output for a saving nobody has measured.

**Found while implementing:** `PlannedSession::$skipped` is `null` on a freshly-created model but
`false` when read back, so a digest over it differed by hydration path. Both booleans are now cast
in `MaterialFingerprint`. That would have shipped as an intermittently-wrong change check.

**Measured while writing it:** `TemariPersona::systemPrompt()` is 15,975 chars, ~4,000 tokens,
re-sent on every turn of every agent run. Affordable only because `prompt_cache_key` is the
narrator `kind`, making it a shared prefix billed at a tenth of the rate on a hit.

**Cutover (recorded in the note)** — prod is wiped and re-synced from Strava, nothing preserved:
re-register, re-authorise, re-enter race goal and preferences. Everything older than 84 days
comes back rule-based and stays that way.

**Folded into the proposed verdicts** — `AnalysisCadence` is a documented trap
(`llm-triggers.md` warns it looks like the origin map and is not).
