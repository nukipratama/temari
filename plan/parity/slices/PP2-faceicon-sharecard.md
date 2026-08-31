# PP2 — Identity: FaceIcon and share card

**Program** prototype parity · **Slot** main (blocking; nothing runs concurrently) ·
**Blockers** `PP0`, `PP1`, `PP3` · **Status** in-review

## Goal

P10 and P11: replace `TemariProto` with the prototype's `FaceIcon` everywhere the prototype draws
one, remove it from the three screens that draw none, cut the mascot rig with its pose map and
accessory-driven variants, and put the brand mark — not a mascot face — on the share card.

Twelve commits. 85 files, +610 / **−3873**.

## What landed

### The per-screen `FaceIcon` placement

Verified against prototype source before writing anything: `FaceIcon` is imported and rendered on
exactly **8 of 11** screens — Today, Plan, Race, History, Activity, Inbox, Profile, Onboarding —
across **13 call sites**, and is absent from Login, Trends and Settings. Both facts hold.

The rule applied: **swap on the eight screens the prototype draws a face on, remove on the three it
does not.** Where the prototype draws the app's own equivalent card *without* a face, remove there
too. Placement within a screen stays `PS1`–`PS11`'s.

| app call site | screen | prototype counterpart | result |
|---|---|---|---|
| `ui/EmptyPanel.tsx` | shared | `NoPlanCard` 40 · `NoRaceState` 40 · `EmptyInboxCard` 40 · `NoRunsCard` 40 · `NoPlanState` 48 | `pose` prop → `face` boolean, `FaceIcon size={48}` |
| `home/TodaySession.tsx` | Today | today message card, `size=42 ring=--leaf` | swap, 42, leaf ring, `DARK_FACE` |
| `home/VerdictHero.tsx` | Today | (byline, no exact counterpart) | swap, 34, defaults |
| `dashboard/LastRunCard.tsx` | Today | last-run card inside the stats disclosure — **no face** | **removed** |
| `history/RecapCard.tsx` | History | `RecapCard`, `size=36 ring=--mood fill=--sky-2 feature=--cream` | swap, exact |
| `run/EmptyRunsState.tsx` | History | `NoRunsCard` 40 (compact); this is a full hero | swap, 72 (the prototype's largest) |
| `run/AskAboutRun.tsx` | Activity | `AskAboutRun` — **no face** | **removed** |
| `pages/Runs/Show.tsx` hero | Activity | `HeroPanel`, `size=56` | swap, 56, `DARK_FACE` |
| `pages/Runs/Show.tsx` narration | Activity | `RunLenses`, `size=40` | swap, 40 |
| `pages/Profile.tsx` | Profile | hero, `size=64 ring=--leaf` | swap, 64, leaf ring, `DARK_FACE` |
| `pages/Plan.tsx` season block | Plan | `SeasonHeaderCard` — **no face** | **removed** |
| `pages/Onboarding/Index.tsx` connected | Onboarding | `ConnectedStep`, `size=72` | swap, 72 |
| `pages/Onboarding/Index.tsx` pace ring | Onboarding | pace-ring centre, `size=26` | swap, exact |
| `pages/Auth/Login.tsx` | Login | **no face anywhere** | **removed** |
| `card/KartuMini.tsx` corner | — | no Kartu in the prototype | → `TemariMark` (matches the share card's own corner) |
| `temari/TemariNudgeModal.tsx` | — | no modal in the prototype | swap, 72 (P1 plumbing) |
| `pages/Devtools/Design.tsx` ×6 | operator | — | fork 2, below |
| `pages/Collection/Accessories.tsx` ×2 | cut page | — | fork 1, below |

`FaceIcon` takes the prototype's raw `ring` / `fill` / `feature` props but defaults them to the
app's ground-reactive tokens (`--color-horizon` / `--color-card` / `--color-foreground`), so most
call sites pass only `size`. `DARK_FACE` (`--color-sky-2` disc, `--color-cream` features) is the
prototype's own inverted read, exported alongside for the four surfaces that are fixed-dark
regardless of ground — the recap cards, and the app's `HeroPanel`.

### The share card, and how the two renderers stay in step

**The plan's premise for P11 is wrong on both halves, and the correction is the important finding
here.**

1. **`shareCard.ts` was already drawing the brand mark, not the mascot.** Its `temariSvg()` is a
   canvas port of `BrandMark.tsx`'s `TemariGlyph` — the *face-glyph brand mark* — not of
   `TemariProto`. Two comments in the file still said "mascot watermark" (`:1038`, `:1494`), which
   is where the plan's claim came from; the code stopped drawing a mascot before this slice.
2. **`RunCardImageRenderer.php` draws no mascot and no glyph at all.** Verified: the file contains
   exactly one path-ish element, a `<polygon>` that is the rarity flag's star (`:547`). Its brand
   signature is a text wordmark, `temari.app`, at `:487`. There was nothing on the server side to
   switch.

So the two renderers were **already divergent on the brand lockup** before this slice — client:
glyph + "Temari" at the card's top-right and, mood-tinted, in the art window's corner; server: a
`temari.app` text stamp at the bottom-right. "They must stay pixel-identical, as they already
must" does not describe the code.

What P11 actually needed, therefore, was a **client-only** change, and that is what landed:
`temariSvg` → `brandMarkSvg`, drawing `TemariMark`'s two nested arcs instead of the face glyph.
Geometry, cache, `drawBrand` lockup, sizes and both call sites are otherwise untouched. **The PHP
renderer was not modified at all**, which is the strongest possible lockstep guarantee for a change
it has no counterpart for — and it keeps `RunCardImageRenderer.php`'s `MIRROR_FILES` entry out of
the diff.

**How the client mark is tested.** `brandMarkSvg` is exported and pinned directly
(`shareCard.test.ts`, "signs every layout with the two-arc brand mark, never a face"): both arc
`d` attributes verbatim, `stroke="none"`-free fill, the horizon outer stroke, the tone-driven inner
stroke, an explicit `not.toContain('<circle')` so a face can never come back, and the mood-tint
override. The same two `d` strings are pinned on the React side in `TemariMark.test.tsx`. Asserting
through `drawShareCard` was tried first and is **not** viable: `temariCache` is module-level, so by
the time a later case runs, `loadImage` is never called again and the data URI is never re-encoded.

The hex set in `shareCard.ts` did not change (`C.ink`, `C.cream`, `C.horizon` were already there),
so `DesignTokenMirrorsTest` needed no `OFF_TOKEN` edit for it.

### One brand mark, not two

The app carried two marks: `HeaderBrandMark`'s ring glyph (already a hand-port of the prototype's
`rack/TemariMark.tsx`) and `BrandMark`'s `TemariGlyph`, a mascot face with a thread band. P10
retires mascot art, and the prototype's only logo is the ring, so the ring becomes the single
definition — `components/TemariMark.tsx` — and `TemariGlyph` is deleted. Four consumers:
`HeaderBrandMark` (shell header), `BrandMark` (Login lockup), `RouteGlyph` (Kartu route-less
watermark, and `KartuMini`'s corner), and `shareCard.ts`.

This touches Login's mark, which is `PS1`'s screen — but it is a glyph swap, not a screen rework,
and it is exactly what the prototype's Login draws (`TemariMark size={26}`).

## Blockers

None. Two forks, both resolved and recorded below.

## Judgement calls

1. **Fork 1 — `Collection/Accessories.tsx`: deleted the page, doing `W1`'s row early.** It is
   already ledger-cut, but it renders `TemariProto`, so deleting the rig breaks its build. The
   options were a shim or the deletion. **Chose deletion**: the page is an equip picker whose
   entire content is a live preview of gear worn by a mascot that no longer exists, so a shim
   leaves a reachable page that cannot do the one thing it is for — the false-hope affordance this
   program keeps removing. Deletion is also reversible and reviewable in a way a stub is not.
   Scope taken: page + test, `AccessoryController` + its feature test, `EquipAccessoryRequest` + its
   test, `GET /accessories`, `POST /api/accessories/equip`, and the `/aksesori` · `/target` ·
   `/goals` redirects that pointed at a route that would now 404. Scope **not** taken (P4, `W2`):
   `EquippedAccessories`, `GoalResolver`, `GrantEligibleUnlocksAction`, `UserUnlock`, the unlock
   configs, the 25 SVGs, and the `equippedAccessories` shared prop. `W1`'s IA-cutover work is
   otherwise untouched.
2. **Fork 2 — `Devtools/Design.tsx`: kept the page, replaced four sections with two.** P20 keeps
   operator pages, but four of this one's sections ("Mascot faces", "Mascot on sky", "Wearable
   slots", "Season coverage") catalogued art this slice deletes; keeping them would mean keeping
   `TemariProto`. They become "Temari's face" (every size the app ships, 26 → 72) and "Temari's face
   on sky" (the inverted read at three ring tints). The page still does what it is for — auditing
   shipped art against the live token set — and `Design.test.tsx` now asserts the shipped size set
   instead of the ten expression names.
3. **`build-mascot.mjs` is kept, against its cut-list row.** The row names it, but it is a brand
   generator, not UI, and `build-accessories.mjs` imports it — and *that* one belongs to the
   Accessories row, i.e. `W2`. Three further preview generators (`build-empty`, `build-cards`,
   `build-screens`) import it too. Deleting it here would drag four unrelated generators out with
   it. Nothing in the app or its tests reaches it any more (both `@brand` module declarations that
   did are gone), and `W5` deletes the whole exploration tree.
4. **Mood now drives a colour, not a pose.** `RecapCard` keeps the prototype's mood-tinted ring via
   `var(--color-mood-${mood})`, matching `MOOD_FILL`'s vocabulary. Everything else in
   `lib/temariPose.ts` (`MOOD_TO_POSE`, `poseForRun`, `poseForFormStatus`) and `RARITY_POSE` in
   `lib/runcard.ts` lost its last reader and went. `runcard.ts` is on `MIRROR_FILES`, so a dead
   pose table there is not free.
5. **`SeasonPhase` moved to `lib/seasonPhase.ts`.** It was declared in `TemariProto.tsx` for the
   thread-coverage overlay; `currentSeasonPhase` is now its only user. Plan's
   `SEASON_VISUAL_CAPTION` went with the overlay — it described the deleted art ("thread just
   getting started, sparse and loosely wound"), not the arc. The phase chip stays.
6. **Two Plan tests asserted the phase through that caption.** `seasonPhase.test.ts` already covers
   the deload walk-back and the base fallback exhaustively, so the redundant one is dropped and the
   deload one is refolded onto the phase chip (`getAllByText('Build')` at length 2 — the season chip
   plus the history week's own — with `Base` absent).
7. **`recentMoods` removed from `DashboardController`, not left for `W2`.** Removing `LastRunCard`'s
   mascot left it with no reader, and its other consumer (the featured-kartu panel) went in `PP3`.
   It is an **eager** Inertia closure, so it was costing a `user_unlocks`-adjacent query on every
   dashboard load for output nobody rendered. `PostRunNoteReader::moodsFor` survives — it is called
   internally by `forActivities`.
8. **`TemariNudgeModal` keeps a face at 72px.** The prototype has no modal at all, so there is no
   counterpart to match; it is P1 plumbing (notification permission, demo-blocked). It stops reading
   the shared `equippedAccessories` prop, which is the accessory-variant cut.
9. **`.claude/skills/browser-review/scripts/verify-mascot.mjs` deleted.** A documented one-off whose
   three subjects — Login's mascot, the eight poses, the share-card mascot — no longer exist.
10. **The thread-ball ADR gets a dated banner, not an edit**, per the immutability rule: the
    decision (replace the bunny; leave the accessory data model alone) still stands, only the
    drawing it chose is gone.

## Where the plan was wrong

Four findings, all from reading the source the claims name.

1. **P11's server half does not exist.** `RunCardImageRenderer.php` draws no mascot — its only
   non-text mark is the rarity star polygon, and its brand signature is the `temari.app` wordmark.
   See "the share card" above. Had this been taken on faith, the slice would have invented server
   geometry to "match" a client change, increasing divergence rather than removing it.
2. **P11's client half was already the brand mark.** `shareCard.ts` drew `BrandMark`'s
   `TemariGlyph`, not `TemariProto`. The real change is *which* brand mark — the face glyph or the
   prototype's ring — which is a question P10 answers, not P11.
3. **The two renderers were not pixel-identical on the brand lockup to begin with**, so "they must
   stay pixel-identical, as they already must" was not a constraint this slice could preserve; it is
   pre-existing divergence, now recorded rather than silently inherited.
4. **The brief's `TemariProto` grep was under-inclusive.** It listed 17 files matching the literal
   string, but eight further render sites reach the mascot through the `Temari` wrapper and never
   name `TemariProto`: `home/TodaySession`, `home/VerdictHero`, `dashboard/LastRunCard`,
   `history/RecapCard`, `card/KartuMini`, `run/AskAboutRun`, `run/EmptyRunsState`,
   `pages/Runs/Show`, `pages/Profile`, `pages/Onboarding/Index`. Grepping for
   `components/temari/Temari'` and `<Temari` alongside is what found them.

P10's own claims held exactly: 8 of 11 screens, 13 call sites, absent from Login, Trends and
Settings.

## Files touched

**Added**: `components/temari/FaceIcon.tsx`, `components/TemariMark.tsx` (+ tests).

**Deleted (8 source + 8 test + 2 controller/request tests + 1 script)**:
`components/temari/{TemariProto,Temari}.tsx`, `lib/{temariPose,equippedAccessories}.ts`,
`pages/Collection/Accessories.tsx`, `app/Http/Controllers/AccessoryController.php`,
`app/Http/Requests/EquipAccessoryRequest.php`, every co-located test plus
`tests/Feature/Accessories/AccessoryControllerTest.php`,
`tests/Unit/Http/Requests/EquipAccessoryRequestTest.php`, and
`.claude/skills/browser-review/scripts/verify-mascot.mjs`.

**Edited — frontend**: `components/{BrandMark,HeaderBrandMark}.tsx`,
`components/card/{RouteGlyph,KartuMini}.tsx`, `components/dashboard/LastRunCard.tsx`,
`components/history/RecapCard.tsx`, `components/home/{TodaySession,VerdictHero,NoVerdictPanel}.tsx`,
`components/run/{AskAboutRun,EmptyRunsState}.tsx`, `components/temari/TemariNudgeModal.tsx`,
`components/ui/EmptyPanel.tsx`, `lib/{shareCard,runcard,seasonPhase}.ts`,
`pages/{Home,Plan,Profile,Race,Inbox}.tsx`, `pages/Activities/Feed.tsx`, `pages/Auth/Login.tsx`,
`pages/Devtools/Design.tsx`, `pages/Onboarding/Index.tsx`, `pages/Runs/{Show.tsx,useRunShow.ts}`,
`types/{inertia.ts,brand.d.ts}` (+ their tests), `layouts/AppShell.test.tsx`,
`components/ui/BackLink.test.tsx`.

**Edited — backend**: `routes/web.php`, `Http/Controllers/DashboardController.php`,
`Services/Gamification/EquippedAccessories.php` (docblock),
`tests/Unit/Architecture/DesignTokenMirrorsTest.php`, `tests/Feature/Dashboard/…`,
`tests/Feature/Inertia/SharedPropCacheBustTest.php`,
`tests/Feature/Http/Middleware/BlockDemoTelegramWritesTest.php`,
`tests/Feature/Smoke/PagesRenderTest.php`.

**Styles / config**: `resources/css/app.css` (nine pose keyframes + `.temari-root`),
`vitest.config.ts` (comment).

**Docs**: `CLAUDE.md`, `README.md`, `.claude/skills/temari/SKILL.md`, `docs/DESIGN.md`,
`docs/design-tokens.md`, `docs/voice-and-tone.md`,
`docs/features/{temari-mascot,targets-accessories,profile,gamification,index,vibe-and-mood,
run-history,onboarding,race-projection}.md`,
`docs/decisions/thread-ball-character-rebrand.md` (dated banner only).

`grounds.json` needed **no** regeneration — this slice paints no new panel background and removes
none; `DesignTokenContrastTest` is green both ways unchanged.

## Acceptance criteria

- [x] `FaceIcon` ported from the prototype and rendered on all 8 of its screens, at the size and
      ring colour each one uses.
- [x] No face on Login, Trends or Settings. Login's is a removal; the other two never had one and
      did not gain one (`EmptyPanel`'s four faceless call sites are exactly the Trends / Profile /
      activity-tiles ones).
- [x] `TemariProto`, `Temari`, `lib/temariPose.ts`, `lib/equippedAccessories.ts` and every mascot
      call site gone; each deleted component's co-located test deleted with it.
- [x] No `EXEMPT` / `TS_EXEMPT` / `EveryClassHasATestTest` exemption added; none orphaned either
      (`AccessoryController` was never exempt).
- [x] The share card carries the brand mark, client-side, with its geometry pinned against the React
      source. The server renderer is byte-unchanged.
- [x] `DesignTokenMirrorsTest` updated on both halves — `TemariProto.tsx` off `MIRROR_FILES`, and
      the seventeen `OFF_TOKEN` entries only it used removed (the allowlist fails closed on dead
      entries).
- [x] No guard weakened, no budget re-baselined. All four routes came in **under** their previous
      numbers.
- [x] `check-doc-citations.php` run directly and green; every doc a deletion made wrong fixed in the
      same commit.
- [x] `CLAUDE.md`'s light-only claim corrected, as its own commit.

## Coverage delta

Measured on this branch against `PP3`'s recorded numbers, same run configuration:

| | `PP3` | `PP2` |
|---|---|---|
| statements | 97.25% (4187/4305) | **97.15%** (3962/4078) |
| branches | 90.68% (3436/3789) | **90.49%** (3229/3568) |
| functions | 96.86% (1111/1147) | **96.78%** (1052/1087) |
| lines | 97.62% (3979/4076) | **97.54%** (3769/3864) |

Down ~0.1pp on every axis, far above the 95% gate, and no test was written to move it. 227
statements left the denominator and 225 of them were covered — the deleted code was **99.1%**
covered against a 97.25% baseline, so removing it lowers the average slightly. `TemariProto.tsx`
and its 300-line generator-parity test were the bulk of that. The two lowest surviving files are
`Runs/Show.tsx` (88.88% statements, 72.72% functions — `PS8`'s, unchanged from `PP3`) and
`Devtools/Design.tsx` (83.63%), neither near the gate.

## Verification notes

Full ladder, in order, all green:

| step | result |
|---|---|
| `pest --group=structure` | 38 passed, 116 assertions |
| `pest --parallel --no-tia` | **3642** passed, 10788 assertions (was 3660 — 18 tests left with their surfaces) |
| `phpstan analyse --debug` | 0 errors |
| `pint` · `rector --dry-run` | clean · 0 changed |
| `tsc` | clean |
| `eslint --max-warnings 0` | clean |
| `npm run build` + `check:chunks` | under budget on all four routes |
| `npm run test:coverage` | 1813 passed, 97.15% statements |
| `check:palette` | 424 files, zero off-token utilities |
| `php scripts/check-doc-citations.php` | all citations resolve |

**Entry chunks — nothing re-baselined.** Every route shrank; raising or lowering a budget would
have been noise.

| route | `PP3` | `PP2` | budget |
|---|---|---|---|
| entry | 111.6 kB gz | 111.5 | — |
| Login | 146.4 | 141.7 | 160 |
| Home | 210.1 | 205.1 | 240 |
| Runs/Show | 221.9 | 216.2 | 245 |
| Profile | 195.4 | 191.1 | 230 |

**Browser sweep, mobile 390px, demo account.** Unlike `PP3` this slice changes rendered art on
eight screens, so it was worth looking. Faces confirmed present and legible on Today (two: the
verdict byline and the leaf-ringed today card), History (two recap cards, one green-ringed and one
purple — the week's mood, working as designed), Profile, Race's no-race empty state, and activity
detail (hero + "what temari says"). Confirmed **absent** from Login, Trends and Settings. The
two-arc `TemariMark` reads correctly in the top bar on every page and on Login. No gap, collapsed
row or stray flex where the much larger mascot used to sit. Inbox and Plan render no face for the
demo account, which is **correct** — their only face is inside `EmptyPanel`, and the demo has both
notifications and a plan.

Onboarding could not be captured (the demo user is already onboarded, so `/onboarding` redirects
to `/`); its two call sites are covered by `Onboarding/Index.test.tsx` at 100% statements.

**A pre-existing bug found, not fixed.** Three `permanentRedirect`s — `/catatan`, `/runs`,
`/progress` — point at bare `/activities`, which has had no route since `S7` folded the activity
list into `/history`. The browser sweep surfaced it as a 404 in the console. It predates this
slice (the same three lines are on `epic/mobile-ux-port`) and IA cleanup is `W1`'s, so it is
recorded here rather than folded in.

## Open questions

1. **The share-card renderers still diverge on their brand signature, and now more visibly.** The
   client draws mark + "Temari" at the top-right and a mood-tinted mark in the art window; the
   server draws a plain `temari.app` text stamp at the bottom-right. That predates this slice, but
   P11 was written on the assumption they matched, so it is worth a decision rather than another
   inheritance. Bringing the server into line is a small, self-contained port of `brandMarkSvg`'s
   two arcs into `RunCardImageRenderer::footer()` — deliberately *not* done here, since inventing
   server geometry to match a client change is outside a slice scoped to identity, and it would put
   a `MIRROR_FILES` backend file into a frontend diff.
2. **`lib/motion.ts`'s `idleByMood` and fidget keyframes are now certainly dead.** They already had
   no importer before this slice (`useIdleFidget` does not exist), and the mascot they were written
   for is gone. `W2`.
3. **`equippedAccessories` is a shared prop nothing reads and nothing can change.** Its only write
   path was the equip route. Its bust-on-write entry in `SharedPropCacheKey` is therefore inert, and
   `SharedPropCacheBustTest`'s bystander case had to be retargeted onto a Telegram revoke to keep
   testing what it was testing. `W2` should take the prop, the cache key and `GamificationProps`'
   contribution together.
4. **`Devtools/Design.tsx` is now the lowest-covered surviving page** at 83.63% statements. It was
   already low; four sections leaving did not help. It is an operator page P20 keeps untouched, so
   raising it is not any screen slice's job — worth a line in `W3`'s successor, or explicitly
   accepting it.
5. **`VerdictHero`'s byline face has no prototype counterpart.** It was swapped rather than removed,
   because Today *is* a face screen and removing a whole byline row is a screen-composition call.
   `PS3` should decide whether the prototype's Today has room for it at all.
