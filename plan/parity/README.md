# Prototype parity — program orchestrator

A re-scope of the remaining mobile-UX port work, grilled with the user on 2026-08-31.

**This directory is additive.** The original program's tracker at [../README.md](../README.md),
its ledger at [../ledger.md](../ledger.md), and its slice docs under [../slices/](../slices/) are
left exactly as they were. Waves 0-2b happened as recorded there; `V0` closed as recorded there.
What changes is everything *after* that point, and it changes enough to need its own tree rather
than a footnote in the old one.

---

## 1. The constraint

> The frozen prototype at [resources/brand/prototype/](../../resources/brand/prototype/) is the
> **source of truth**. If the prototype doesn't have it, the shipped app doesn't either.

This reverses **decision 5** of the original program ("visual parity is explicitly *not* promised")
and overrides several `ledger.md` verdicts that were final as of 2026-08-28. Every reversal is
recorded in [cut-list.md](cut-list.md) with the verdict it replaces.

It does **not** mean the mockup is copied literally in every respect. Decision P1 below draws that
line precisely.

---

## 2. Decisions

Thirty, settled across eight grilling rounds on 2026-08-31. Same rule as the original program: a
later slice must not silently re-open one of these. To change one, add a row to the amendments log
(§6) and edit the entry here in the same commit.

### Scope and interpretation

| # | Decision |
|---|---|
| P1 | **"Identical" means visual/structural parity, not literal one-for-one.** Every screen renders the prototype's exact layout, sections, order and styling, populated with real data. The plumbing a real app needs but a static mockup cannot draw **survives**: loading states, error states, empty states, AI-pending states, retry affordances, pagination, auth. Any *feature area* the prototype does not draw is still cut. |
| P2 | **Pixel fidelity is token-nearest.** Reproduce the prototype's layout, sizing, spacing and radii as closely as the existing token scale allows, snapping each value to its nearest step. Do not add one-off literal values or weaken `check:palette` / the radius guard to accommodate a hardcoded `rounded-[14px]`. A handful of values landing 1-2px off is accepted; the design-system guards are not. |
| P3 | **Where the prototype draws a control but wires it to nothing, implement the real behaviour.** Its Trends range tabs set state nothing reads; its "load older" reveals swap in a second hardcoded array. Those are mockup limitations, not design decisions: the range tabs genuinely refetch/filter, load-older genuinely pages. |
| P4 | **Cut depth: UI and routes now, backend later.** A cut removes the frontend and makes the surface unreachable. Orphaned models, tables, jobs and services are swept by the original program's `W2` (dead-code sweep), which exists for exactly this. Keeps each PR reviewable and each cut reversible. |
| P5 | **Responsive: adopt the prototype's own model.** ~~The prototype has zero responsive classes.~~ **Corrected 2026-08-31, see §6** — it has a real responsive layer built on **container queries** (`@min-[900px]:`, 93 occurrences across all 11 screens), which a `sm:`/`md:`/`lg:` grep does not find. `PhoneFrame.tsx:47` sets `[container-type:inline-size]`, so it fires at the desktop and wide viewports but not tablet. **One breakpoint, at 900px.** Below it: the single mobile column as drawn. Above it: a centred **760px** column (**520px** Onboarding, **440px** Login's auth card), reduced top padding (`pt-16` → `pt-6`, except Onboarding which does not shrink), and **eleven** wide-only reflows — including Login's feature list stacked → `grid-cols-3` and its CTA row → `inline-grid grid-cols-2`, Settings' HR-zone bounds grid inside `ZonesDisclosure` `grid-cols-2` → `grid-cols-4` (**not** the appearance toggle group, which takes no container query), and Profile's right-aligned block revealed by `hidden @min-[900px]:block`, plus gap, type-scale, border, shadow and background changes. [reference.md](reference.md) holds the full per-screen list; implement from it, not from this summary. Collapse only desktop layout the prototype does *not* specify. |

### Navigation and shell

| # | Decision |
|---|---|
| P6 | **Push-screen nav model, adopted exactly.** Only 5 screens carry the bottom nav: Today, Plan, Race, Trends, History — and **Race lights the `plan` tab**, being a sub-page of Plan. Activity, Inbox, Profile and Settings are *pushed* screens: a dedicated back-chevron topbar, no bottom nav. Login and Onboarding have no chrome at all. |
| P7 | **Nav entry points follow the prototype's topbars.** The brand topbar (on the 5 nav screens) carries a bell → Inbox and an avatar circle → Profile. Profile's own topbar carries a gear → Settings. Every pushed screen gets a back chevron. |
| P8 | **`MeTabs` is cut.** The Profile/Settings tab bar has no prototype equivalent; P7's topbar affordances replace it. |
| P9 | **Desktop `TopNav` is deleted entirely.** The floating bottom pill is the only nav at every width. This **reverts fork 5** of `V0` (merged in [#678](https://github.com/nukipratama/temari/pull/678)), which built a desktop nav bar — a decision made before P5 and P6 existed. |
| P31 | ~~**The content column caps at ~512px.**~~ **Superseded by the corrected P5, see §6.** The width is the prototype's own: **760px** above the 900px breakpoint, **520px** for Onboarding, **440px** for Login's auth card. `PageContainer`'s `max-w-page` / `max-w-page-2xl` and its `sm:`/`lg:`/`2xl:` padding steps are replaced by the prototype's single container-query step. |
| P32 | **The bottom-nav pill is constrained to the content column**, centred, rather than spanning the frame as the prototype's `inset-x-3.5` does. **This is a real divergence, not a gap-filling choice**: the prototype gives `AppTopbar` and `AppBottomNav` zero container queries, deliberately keeping the chrome full-bleed while narrowing content to 760px. We diverge anyway, because at 1536px a full-bleed pill puts its four items uncomfortably far apart. Recorded in [cut-list.md](cut-list.md) §4. |
| P33 | **Back goes to a fixed parent per pushed screen**: Activity → History, Inbox → Today, Profile → Today, Settings → Profile. Not browser history, which does nothing useful when a user deep-links in from a notification or a shared URL. `F4`'s existing `BACK_TARGETS` map is the mechanism. |
| P35 | **Any routed screen named by neither P6 list defaults to pushed chrome** — back chevron, no bottom nav. Currently that means `Collection/Accessories` (already ledger-cut; `W1` deletes it outright). `Activities/Feed` and `Activities/Calendar` are *not* in this category: `History.tsx` renders them behind a `?view=` param, so they inherit History's nav chrome. Operator pages set no layout at all, so P20 holds without action. |
| P34 | **Onboarding moves onto `BareShell`**, the existing chrome-free layout Login already uses. **Entry-chunk budgets in `scripts/check-entry-chunks.mjs` may be re-baselined** as part of this program — the rebrand legitimately changes what each route loads. Re-measure and set honest new numbers; do not delete or weaken the guard itself. |

### Identity and art

| # | Decision |
|---|---|
| P10 | **`TemariProto` is replaced by the prototype's `FaceIcon`.** The prototype's simple line-art ring+face SVG becomes the app's only mascot, used exactly where and how the prototype uses it — **8 of 11 screens** (Today, Plan, Race, History, Activity, Inbox, Profile, Onboarding). It is **absent from Login, Trends and Settings**, and those screens must not gain one. The elaborate mascot asset, its pose system, and the accessory-driven variants are cut. |
| P11 | **The share card carries the Temari logo, not a mascot.** ~~`shareCard.ts` (client canvas) and `RunCardImageRenderer.php` (server) both currently draw the mascot; both switch to the brand mark. They must stay pixel-identical to each other, as they already must today.~~ **Corrected 2026-08-31, see §6** — the server renderer draws no mascot and no glyph (only a `temari.app` text wordmark), and the client was already drawing a *brand mark* (`BrandMark`'s face glyph), not the mascot. The two already diverge on their brand signature. P11 lands as a client-only swap onto the prototype's ring mark; the server is untouched, and closing that divergence is its own decision. |

### Feature disposition

Full per-feature detail, including which `ledger.md` verdict each one reverses, is in
[cut-list.md](cut-list.md). Summarised:

| # | Decision |
|---|---|
| P12 | **Kartu survives as generation + a share button.** Cards are still earned per run. The only way to see one is a share button on its run's detail page, opening the share-card popup. **Cut**: the card-reveal modal, the pending-reveal flow, and the `/api/cards/{card}/seen` + `/replay` routes that serve it. **Kept** (the prototype draws all three): Login's "a card for every run" teaser, History's calendar kartu badge, Inbox unlock rows with rarity badges. There is no collection page to cut — none exists. |
| P13 | **Persona mix is cut, replaced by time-in-zone.** The prototype's Profile hero draws a Z1-Z5 heart-rate-zone bar with a legend in the exact slot the shipped app draws its behavioural persona mix. `PersonaMixTool` goes; the zone data already exists (Settings has a zones editor). Reverses ledger "restyle". |
| P14 | **Badges keep being earned, and surface in exactly two places.** Trends' fitness-panel chips and Inbox unlock rows. **Cut**: the unlock toast, the accessory-unlock modal, any badge gallery, and activity detail's "why this earned Common" explainer. Reverses part of the ledger's "restyle". |
| P15 | **Badge chips show every earned badge, wrapping.** The prototype hardcodes three; a real account earns a variable number, so the row wraps rather than truncating to three. A deliberate, recorded divergence from the mockup's fixed count. |
| P16 | **The real Leaflet route map is kept**, styled into the slot the prototype fills with a decorative "activate map" placeholder. That placeholder is a mockup stand-in for a real map, not a decision to have none. Confirms the ledger's "keep, mechanical". |
| P17 | **Dawn-shift is cut.** The light ground becomes a single static palette. Reverses ledger "keep, light-ground only". |
| P18 | **Relative effort is cut.** The prototype's vitals card draws HR avg/max, cadence, steepest grade, flat pace and decoupling — no relative effort. Reverses ledger "keep, mechanical". |
| P19 | **Run lenses are kept.** The prototype's `RunLenses` ("what temari says" narration card with a claims list) is drawn on its activity-detail screen. Confirms the ledger's "keep". |
| P20 | **Operator pages are untouched.** Legal pages stay (the prototype links to them from Settings and the Login footer). Devtools, Devtools/Design and AI-usage stay behind their auth gate — operator tooling sits outside the product surface the prototype specifies, and the AI dead-letter re-arm UI is load-bearing. |
| P21 | **Accessories stays cut**, as already ruled. No change. |

### Screen-level structure

| # | Decision |
|---|---|
| P22 | **Plan gets the prototype's nested timeline.** Week-cluster ("N weeks behind/ahead") → week → planned-vs-actual daily bar chart + day rows → per-day expansion showing a zone-coloured session-segment bar, "temari's take", and a "view activity" link. This replaces the shipped flat day-card list. `S4` skipped this nesting to avoid hiding day-action buttons; P23 removes that objection. |
| P23 | **Plan day-actions: keep Skip and Move, cut Pin, Block and Delete.** Skip and Move **are** in the prototype (`onSkipSession`, `onMoveSession`, a `canSkip`/`canMove` guard, and a weekday-picker for move targets). Pin, Block and Delete appear nowhere in it. Skip and Move live inside the expanded day row, as the prototype places them. |
| P24 | **Season surfaces adopt the prototype's shapes.** Plan gets its `SeasonHeaderCard` (week X of N, adherence %, phase bar, "temari's take") — largely built already by `V0` fork 3. Profile gets its small `SeasonCard` (phase bar + a single progress line). **Cut**: Plan's "Season Track" 5-goal-tier module and `SeasonStreakPanel`'s five-row layout. Season goals still exist in the backend, surfaced as the prototype's single headline line. |
| P25 | **Trends is cut to exactly four blocks**: eyebrow + headline, the range tab bar, the "temari's read" narration card, and one fitness panel (CTL solid + ATL dashed chart, stat tiles, badge chips). **Cut**: milestones, the badge board, strain & monotony, VDOT/pace history, and the personal-bests table. |
| P26 | **Race is cut to three blocks**: race card, projection gauge, goal form. Its CTL/ATL fitness chart is cut — that chart exists once, on Trends, as the prototype arranges it. |
| P27 | **Today's day-grained streak readout is cut.** The prototype's plan card draws a credited/total progress ring and a phase badge with a sparkline, and no "in a row" line. This supersedes part of the 2026-08-30 streak-redesign amendment: "streak" now survives only as the week-streak badge chip on Trends. |
| P28 | **Activity detail is rebuilt to the prototype's section list and order.** Note the earlier `V0` audit was wrong about this screen — the prototype **does** draw the Ask-about-this-run Q&A panel, the narration card, the vitals card, the per-km splits chart and the laps carousel. Genuinely cut: the achievement/collectible block, the "why this earned Common" explainer, and the "Resync from Strava / Send notification" utility row. Also reworked: hero stat hierarchy, and the title / tag-pill / mood-icon treatment. |
| P29 | **Today's "Temari's top pick" featured-card panel is cut** (a Kartu surface the prototype does not draw). |
| P30 | **Demo seed must populate every surviving surface, happy path.** Every screen that survives the cut renders real, representative content for the demo account: a full season with phase variety and a mix of compliance statuses, an active race with a projection, earned badges, inbox variety across kinds, HR zones set, a run with GPS/laps/splits/weather, PR history for the journey chart, and narration filled everywhere. Deliberately-reachable empty states are **not** required. |

---

## 3. Where the earlier audit was wrong

Recorded because two of these directly shaped decisions the user made, and because the same mistake
is easy to repeat.

`V0`'s comparison sweep screenshotted the prototype at its **fixed 844px frame height**, capturing
only what was visible without scrolling, and never opened its collapsed disclosures. Three things
were reported as "absent from the prototype" that are in fact present:

- **Activity detail's Q&A panel, narration card, vitals card, splits chart and laps carousel** are
  all drawn by `ActivityDetailScreen.tsx`. The page being long is not itself a divergence.
- **Today's weekly stats, the three vital bars, the last-run card and the condition card** are all
  drawn — inside the prototype's own collapsed "this week's stats" disclosure.
- **Kartu** is referenced in three places (Login teaser, History calendar badge, Inbox unlock
  rarity), so it was never a clean cut.

A full-scroll capture across all five viewports is the first thing `PP0` does, so the parity work
starts from a correct picture rather than this one.

---

## 4. Slice map

Foundation first (these are cross-cutting and would collide across parallel worktrees), then one
slice per screen, run 3-at-a-time in worktrees as every prior wave did.

```
foundation ──  PP0 → PP1 → PP2 → PP3      (serialized; main checkout)

screens    ──  PS1 … PS11                  (3 parallel worktree slots)

close      ──  PP4                         (demo seed, after every screen slice)

then       ──  the original program's W1 → W2 → W5   (wave 3 cleanup, unchanged)
```

| id | name | what it does |
|---|---|---|
| `PP0` | Full-scroll reference capture | Capture every prototype screen at full scroll height across all 5 viewports, with disclosures opened. The reference every screen slice measures against. No app code. |
| `PP1` | Shell, nav and responsive model | P5-P9: single fluid column to a max-width, push-screen nav model, topbar entry points, delete `TopNav` and `MeTabs`, revert fork 5. Touches every screen's chrome, so nothing else runs concurrently. |
| `PP2` | Identity: FaceIcon and share card | P10-P11: replace `TemariProto` with `FaceIcon` everywhere; re-cut the share card onto the brand mark, client and server in lockstep. |
| `PP3` | The cut | P4-depth deletion of every cut surface in [cut-list.md](cut-list.md): reveal modal + its API routes, persona mix, dawn-shift, relative effort, unlock toast, badge gallery, Today's top-pick panel, the resync/notify row, Trends' and Race's cut sections, Season Track, `SeasonStreakPanel`'s tier rows. UI and routes only. |
| `PS1`-`PS11` | Per-screen exact match | One per prototype screen (Login, Onboarding, Today, Plan, Race, Trends, History, Activity, Inbox, Profile, Settings). Each reworks its screen to the prototype's section list, order and treatment at P2 fidelity, against `PP0`'s reference captures. `PS4` (Plan) is the largest — it builds P22's nested timeline. |
| `PP4` | Demo seed completeness | P30: every surviving surface populated for the demo account. Runs last, once the cut list and every screen are settled, so it seeds the real final shape. |

`W3` (coverage reconciliation) from the original program is **dropped** — it existed to pay down
coverage debt across screens this program now rewrites; each slice here carries its own coverage.
`W1`, `W2` and `W5` still apply and run after `PP4`.

---

## 5. Progress

Status vocabulary: `todo` · `in-progress` · `in-review` · `merged` · `blocked` · `cut`.

| id | name | status | PR | slot | notes |
|---|---|---|---|---|---|
| PP0 | Full-scroll reference capture | merged | [#681](https://github.com/nukipratama/temari/pull/681) | main | 90 captures + [reference.md](reference.md); found 5 factual errors in this plan; squashed as 1e45a122 |
| PP1 | Shell, nav, responsive | merged | [#682](https://github.com/nukipratama/temari/pull/682) | main | [PP1-shell-nav](slices/PP1-shell-nav.md); `TopNav`+`MeTabs` deleted, push-screen nav, 900px/760px layer across 24 files; 8 of 11 wide-only reflows carried, 3 deferred to `PS1`/`PS11`; no budget re-baselined; squashed as ed227cc1 |
| PP2 | FaceIcon + share card | merged | [#684](https://github.com/nukipratama/temari/pull/684) | main | [PP2-faceicon-sharecard](slices/PP2-faceicon-sharecard.md); 14 commits, 87 files, −3875 lines; squashed as 1da1aebb; `FaceIcon` on all 8 prototype screens, removed from Login and from the three cards the prototype draws faceless; one brand mark (`TemariMark`) now, on the share card too; four plan errors found — **P11's server half does not exist** (`RunCardImageRenderer` draws no mascot, only a `temari.app` wordmark) and `shareCard.ts` already drew the brand mark, so the change is client-only and the PHP renderer is byte-unchanged; both forks resolved (Accessories page deleted, `Devtools/Design` respecimened); coverage 97.25% → 97.15%; two CI-only guard failures fixed on the branch (orphaned `aksesori` `ALLOWED` entry, a `{@see}` at the deleted `AccessoryController`) — the trigger for slice `C1` |
| PP3 | The cut | merged | [#683](https://github.com/nukipratama/temari/pull/683) | main | [PP3-the-cut](slices/PP3-the-cut.md); 12 commits, 124 files, −7057 lines; every §1 row cut except `TemariProto` (`PP2`), Onboarding chrome (`PP1` already did it) and Accessories (`W1`); one cut-list error found (dawn-shift's `shareCard.ts` consumer is a colorway id, not a consumer); coverage 95.51% → 97.25%; squashed as e9309b6b |
| PS1 | Login | todo | — | wt | |
| PS2 | Onboarding | todo | — | wt | |
| PS3 | Today | todo | — | wt | |
| PS4 | Plan | todo | — | wt | largest; nested timeline |
| PS5 | Race | todo | — | wt | |
| PS6 | Trends | todo | — | wt | |
| PS7 | History | todo | — | wt | |
| PS8 | Activity detail | in-review | [#687](https://github.com/nukipratama/temari/pull/687) | 2 | [PS8-activity-detail](slices/PS8-activity-detail.md); 15 commits, 44 files; rebuilt to the prototype's section list — all five sections `V0` wrongly called absent kept; four renames to the prototype's vocabulary (`PastYouHero`→`PastYouCard`, `DetailTiles`→`VitalsCard`, `SplitsTable`→`SplitsChart`, `LapsGraph`→`LapsCarousel`) plus a new `RunHero`; the on-page kartu block cut, orphaning `Kartu`/`KartuMount`/`ZoneBar` (share path via `ShareCardModal` verified untouched); no plan/prototype discrepancy found; coverage 97.15% → 97.21%; `Runs/Show` 206.3 kB gz against a 245 kB budget, no re-baseline |
| PS9 | Inbox | todo | — | wt | |
| PS10 | Profile | todo | — | wt | |
| PS11 | Settings | todo | — | wt | |
| PP4 | Demo seed completeness | todo | — | main | after every screen slice |

---

## 6. Amendments

Every deviation from §2 lands here, dated, with the reason. Empty is the healthy state.

| date | decision | change | why |
|---|---|---|---|
| 2026-08-31 | 5, 10, 12, `V0` fork 4 | **Four further factual errors corrected, all found by `PP0`'s source cross-check.** (a) P5 said "four wide-only layouts"; there are **eleven**, and Settings' `grid-cols-2` → `grid-cols-4` is the HR-zone bounds grid, not the appearance toggle; Onboarding's `pt-16` does not shrink. (b) P10 said `FaceIcon` is on "9 of 11 screens"; it is on **8**, and is absent from Login, Trends and Settings. (c) `cut-list.md` presented Kartu's share button as prototype-backed; the prototype draws **no share button and no dialog anywhere**. The button stays, reclassified as a deliberate divergence (§4) so a generated card is viewable at all. (d) Today's stats disclosure renders **closed** in the prototype (`TodayScreen.tsx:464`, no `defaultOpen`); this **supersedes `V0` fork 4**, which chose open-by-default under the old parity-optional rule and partly on a screenshot that never opened it. | `PP0`'s brief made cross-checking `cut-list.md` against prototype source a primary deliverable, precisely because P5's earlier error had shown the plan's factual claims needed independent verification. It found five discrepancies; four are corrected here and one (VDOT/PR history surviving on Profile) was a scoping ambiguity clarified in place. Decisions (c) and (d) went back to the user rather than being patched silently. |
| 2026-08-31 | 5, 31, 32 | **P5's central factual claim was false and is corrected.** P5 asserted the prototype had "zero responsive classes ... content never reflows". It does reflow: it uses **container queries** (`@min-[900px]:`, 93 occurrences across all 11 screens) rather than breakpoint prefixes. P5 now describes the prototype's real model (900px breakpoint; 760/520/440px columns; four wide-only layouts). P31's invented 512px column is superseded by the prototype's own 760px. P32 survives, but is reclassified from "filling a gap the prototype left" to a **deliberate divergence against a choice the prototype actually made** — it gives its chrome no container queries on purpose. [cut-list.md](cut-list.md) §4's corresponding row is corrected too. | The claim came from a grep run during the grilling session that tested for `sm:`/`md:`/`lg:`/`xl:` prefixes and for `@sm`/`@md`/`@lg`/`@container`, but not for the arbitrary-value form `@min-[900px]:` the prototype actually uses. It returned zero and was reported to the user as fact; three decisions were then made on top of it. Caught by the `PP1` agent, which stopped before writing any code rather than implementing against a premise it had verified as false, and re-verified independently against the `prototype-frozen` tag before the correction landed. |
| 2026-08-31 | 11, cut-list §1 `TemariProto` row | **P11's premise is factually wrong on both halves, corrected here.** P11 says `shareCard.ts` and `RunCardImageRenderer.php` "both currently draw the mascot" and "must stay pixel-identical to each other, as they already must". Neither holds. (a) `RunCardImageRenderer.php` draws **no mascot and no glyph at all** — its only non-text mark is the rarity flag's star `<polygon>` (`:547`), and its brand signature is a `temari.app` text wordmark (`:487`). (b) `shareCard.ts` was already drawing a **brand mark**, not the mascot: `temariSvg()` is a canvas port of `BrandMark.tsx`'s `TemariGlyph`, mislabelled by two stale "mascot watermark" comments. (c) The two therefore **already diverged** on the brand lockup — client: mark + "Temari" top-right plus a mood-tinted mark in the art window; server: a bottom-right `temari.app` stamp. P11 is implemented as a **client-only** change (the face glyph → the prototype's ring mark, geometry pinned via an exported `brandMarkSvg`); the PHP renderer is byte-unchanged. Separately, the cut-list row's `build-mascot.mjs` is **kept**: it is a brand generator rather than UI, and `build-accessories.mjs` — which the Accessories row assigns to `W2` — imports it, along with three preview generators. | Found by `PP2` reading the two renderers before editing either, as the standing rule requires. Acting on the claim would have meant inventing server-side geometry to "match" a client change, which increases divergence and drags a `MIRROR_FILES` backend file into a frontend diff. Bringing the server's signature into line with the client's is a real, separate decision — recorded as [PP2-faceicon-sharecard](slices/PP2-faceicon-sharecard.md) open question 1, not taken here. |
| 2026-08-31 | §4 slice map (sequencing only) | **`PP3` ran before `PP2`**, reversing the slice map's `PP0 → PP1 → PP2 → PP3` order. No decision changed. | The two slices overlap on five files, and `PP3` *deletes* two that `PP2` would *edit* (`CardReveal.tsx`, `AccessoryUnlockModal.tsx`, plus `equippedAccessories.ts`, `useRunShow.ts`, `Plan.tsx`) — parallel worktrees would have produced delete/modify conflicts, and the mapped order would have migrated `FaceIcon` into components about to be deleted. Cutting first shrank `PP2`'s call-site list instead. |
| 2026-08-31 | P4 (depth), noted not changed | **`briefing_featured_kartu_voice` still generates for the deleted Today panel** and is left for `W2`, with the reasoning recorded rather than acted on. | The row is keyed on the featured card id, so it re-bills when the pick *changes*, not nightly. Stopping it means deleting the dispatch block at `DailyBriefingCommand.php:44-55` (**not** the `cadence()` enum, which nothing dispatches off) plus non-mechanical surgery on three assertion sites in `DailyBriefingCommandTest`. Folding a backend behaviour change into a slice scoped "UI and routes only" is what P4 exists to prevent; if `W2` slips, this goes in its own one-commit PR. |

---

## 7. Carried over unchanged

These still hold from the original program and are not re-litigated here:

- **Branching**: every slice PR targets `epic/mobile-ux-port`. No merge to `main` until the whole
  chain (this program → `epic/rebrand-temari` → `main`) is deliberately merged.
- **Tests**: the 1:1 class↔test convention and the 95% coverage gate are untouched. Deleting a
  component deletes its test with it.
- **Verification**: the full ladder in [../README.md](../README.md) §9 runs before every PR.
  `grounds.json` is regenerated by any slice touching panel backgrounds.
- **The prototype stays frozen and read-only** (decision 19). A spec change goes into the owning
  slice doc, never into the prototype. It is still deleted by `W5` at the end.
- **Coupling** listed in [../README.md](../README.md) §8 still bites, especially `grounds.json`
  across parallel worktrees.
