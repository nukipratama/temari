# PP3 — The cut

**Program** prototype parity · **Slot** main (blocking; nothing runs concurrently) ·
**Blockers** `PP0`, `PP1` · **Status** in-review

## Goal

Carry P4-depth deletion of every surface in [cut-list.md](../cut-list.md) §1 except the three rows
this slice does not own: `TemariProto` (`PP2`), App-shell chrome on Onboarding (already done by
`PP1`), and Accessories (`W1`/`W2`). UI and routes only — the surface becomes unreachable; orphaned
models, tables, jobs and domain services are `W2`'s.

Ten commits, one per feature. 122 files, +265 / **-7056**.

## What landed

### The cut-list rows, and what each one actually removed

| row | commit | removed | left standing |
|---|---|---|---|
| Card reveal | `7523bb25` | `CardReveal` + `PackWrapper` (orphaned by it) and their tests, the `AppShell` mount, activity detail's "Replay card reveal", the inbox post-run replay, `api.cards.seen` / `api.cards.replay`, `CardSeenController` / `CardReplayController` + `CardSeenTest`, the `pendingReveal` shared prop and its type | `pending_reveal_card_id`, `RunCardFactory`'s write to it, the inbox row's `run_card_id` |
| Unlock toast + accessory-unlock modal | `40a594b9` | `UnlockToast`, `AccessoryUnlockModal`, `ConfettiBurst` (orphaned) + tests, the `flash.unlock` shared prop, `InboxController::celebration()` and the row's `unlock` key, the `UnlockFlash` type, `InboxRow`'s whole replay affordance | `GrantEligibleUnlocksAction` still flashes `unlock` with nothing reading it; `useModal` and `keyToPreviewEquipped` (used elsewhere) |
| Today's featured-kartu panel | `c8f69d4c` | `FeaturedKartuPanel`, `FeaturedCardHero` + tests, `Home/helpers`' `featuredCardFor` / `toFeaturedCard` / `FeaturedCard`, `BriefingResult`'s `featuredKartuVoice` + `featuredCardId` and `BriefingComposer`'s resolution of them | `ResolveFeaturedKartuAction`, the `briefing_featured_kartu_voice` narrator, its job, its `AnalysisType` case — `ai:daily-briefing` still fills the row |
| Persona mix | `bdee3e4c` | `PersonaBar` + test, the `personaMix` Inertia prop | `AkuProfileVoiceNarrator::personaMix()` and `PersonaMixTool` — the hero voice still reads the mix as narration context |
| Relative effort | `9b79d819` | the "harder than usual" sub-line under the TRIMP tile, `RelativeEffortPayload` / `EFFORT_SUB` / `effortSub` in `useRunShow`, the `relativeEffort` prop | `RelativeEffort` itself: `EffortContextTool` (four narrators) and `PastYouTrendBuilder`'s `relative_effort_band` still read it |
| "Why this earned X" explainer | `7cce4650` | the composite-rarity `Card` and its per-badge ability list | the card, its flavor quote, the share button |
| Resync / notify row | `ef5250b8` | both buttons, `activities.resync` + `activities.send`, `ResyncActivityController` / `SendActivityNotificationController` + their tests, the `notificationRetryAfterSeconds` prop | `ResyncActivityJob` (webhook path), `SendNotificationButton` / `StravaAction` / `usePendingPost` (recaps + Settings), `PushesAnalysisNotification` (two recap controllers) |
| Trends' milestones, badge board, strain & monotony, VDOT/pace history, personal bests | `546ec767` | `LoadTrend`, `VdotTrend`, `PaceConsistencyTrend`, `PersonalBests` + tests, the "always full history" copy block, six controller props, and `FitnessTrend`'s milestone apparatus | `FitnessTrend`'s chart, stat tiles, badge chips and badge-detail panel; `StreakBadge`; VDOT and PR history on Profile |
| Race's CTL/ATL chart usage | `be25f315` | the "Fitness · last 90 days" section and the `ctlTrend` prop | `CtlTrendChart` (Trends still draws it), `TrainingLoad::ctlTrend()` |
| Plan's "Season Track" | `be25f315` | the `SeasonTrack` tier rail | the `GoalCard` grid, `GrantSeasonUnlocksAction`, `tiers_kept_from_past_seasons` |
| Profile's `SeasonStreakPanel` | `be25f315` | the five-row panel, the `seasonStreak` prop, `StreakPanel.ts` (its only reader) | `SeasonStreakSummaryBuilder` (Plan + Trends still call it) |
| Today's day-grained streak | `be25f315` | the "N Credited In A Row" line, `streak_days`, `CurrentWeekPlanBuilder::streakDays()` | the week-streak chip on Trends |
| Dawn-shift | `88911428` | `useDawnShift` + test, its two shell calls, the four `body[data-time-of-day]` rules in `app.css`, `designTokens.ts`'s ground scraping, `DesignTokenContrastTest`'s `$shifts` plumbing | `grounds.mjs`'s `readDawnShiftSurfaces()` and its two brand-script callers |
| `TemariProto` mascot system | — | **not this slice.** `PP2` owns it. Untouched even inside files edited here | |
| App-shell chrome on Onboarding | — | **already done.** Verified: `Onboarding/Index.tsx:713` sets `bareLayout`. Row is complete | |
| Accessories | — | **no change**, per the row itself | |

### Judgement calls

1. **Controllers that solely serve a deleted route are deleted with their tests.** Applied to all
   four (`CardSeen`, `CardReplay`, `ResyncActivity`, `SendActivityNotification`). The alternative —
   orphan the controller for `W2` — is not self-consistent here: with the route gone its test can no
   longer reach it, and dropping only the test trips the 1:1 gate. So it is route + controller +
   test together, or nothing.
2. **Trends' "milestones" vs "badge chips".** The cut row lists *milestones* and *badge board* among
   the cut while P25 keeps "badge chips" in the surviving fitness panel — the two read as if they
   name the same code. [reference.md](../reference.md) §8 settles it: the prototype's `FitnessPanel`
   draws badge chips (4e) **and** a badge detail panel (4f), and its "Explicitly absent" list says
   *no milestones section* and *no badge board (the chip row lives inside `FitnessPanel`, it is not
   a standalone board)*. So the chip row and detail panel stay, and what goes is the framing around
   them: the Chart.js plugin plotting badge markers on the line with its clustering and
   tap-through, the "Badge earned" legend swatch, and the "Milestones on this stretch" heading and
   count. `FitnessTrend` now matches the prototype's 4a-4f structurally, which is what `PS6` needs.
3. **`StreakBadge` survives**, though it renders a panel literally labelled "Badge board". P27 keeps
   the week-streak chip on Trends explicitly, and §2 lists it. Folding the chip into the fitness
   panel and dropping the standalone panel framing is a restyle, so it is `PS6`'s, not a deletion.
4. **Profile's `seasonStreak` prop is removed, not kept for `PS10`.** P24 replaces the panel with the
   prototype's small `SeasonCard` in the same slot, so the prop has a future reader — but keeping it
   meant a destructured prop nothing renders (a lint error) or dead plumbing. `PS10` re-adds it; the
   builder it calls is untouched and [profile.md](../../../docs/features/profile.md) records exactly
   what to re-wire, including the `peekCurrent()` (never `ensureCurrent()`) constraint.
5. **`InboxRow`'s replay button is removed outright, not disabled.** Both celebrations it could
   trigger are gone, and the prototype's `InboxRow` draws no such control
   ([reference.md](../reference.md) §11). A button that can no longer do anything is a false-hope
   affordance.
6. **`records.md` was rewritten rather than deleted.** Its whole subject was the Personal Bests
   panel. PRs still exist and still surface (Profile's `ProgressionCard`), so the note now documents
   that home and records both retirements.
7. **The `ink-grounds-derived-not-listed` ADR gets a dated banner**, not a rewrite. Its decision
   ("derive the ground set, don't list it") is unchanged and still enforced; only the *fact* that
   five dawn-shift surfaces are part of that set has changed.

### Where the cut list was wrong

One row, found by reading the source it names:

- **Dawn-shift names `lib/shareCard.ts` as a consumer. It is not one.** `shareCard.ts`'s `dawn` is a
  share-card **colorway id** (`'navy' | 'dawn' | 'ember'`), unrelated to time of day; its only link
  to the feature was a prose comment at `:123` explaining why `CARD_GROUND` is `--color-cream-deep`
  rather than `--color-surface`. The comment was corrected, nothing else changed there. This matters
  beyond pedantry: `shareCard.ts` is on `DesignTokenMirrorsTest`'s `MIRROR_FILES`, and the plan's
  §8 coupling note warned that a frontend-only edit there fails the **backend** structure group.
  Because the edit touches no hex literal and no geometry, the mirror stayed intact — but had the
  row been right, cutting a real consumer would have needed `RunCardImageRenderer.php` to move in
  lockstep, which is not a `PP3`-shaped change.

Everything else in §1 was accurate: every file, route and prop named exists at the path given and
does what the row says.

### Deliberately left for `W2`

Recorded so the sweep has a list rather than a rediscovery:

- `pending_reveal_card_id` (column, cast, `Fillable`), `RunCardFactory`'s write to it, and
  `DemoSeedCommand` / `RunCardFactoryTest` assertions on it.
- `GrantEligibleUnlocksAction`'s `Session::flash('unlock', …)` — nothing reads the flash now.
- `ResolveFeaturedKartuAction`, `BriefingFeaturedKartuVoiceNarrator`,
  `AnalyzeBriefingFeaturedKartuVoiceJob`, `AnalysisType::BriefingFeaturedKartuVoice` and its
  `SelfHealer` / `BackfillAgeGate` / `AnalysisSubjectAuthorizer` / `RuleBasedNarrationFiller` /
  `DailyBriefingCommand` / `DemoRunSeeder` wiring. Removing the enum case is a whole-pipeline change,
  not a UI cut.
- `AkuProfileVoiceNarrator::personaMix()` (still used as narration context, so possibly a keep).
- `TrainingLoad::strainMonotonyTrend()` — nothing reads it now.
- `PrCategory::efforts()`'s pace-window PRs — stored, no longer drawn anywhere.
- `resources/brand/grounds.mjs`'s `readDawnShiftSurfaces()` and its callers in
  `build-directions.mjs` / `apply-pewter.mjs`. They degrade to an empty map, so the `.mjs` and PHP
  ground sets still agree; only the dead code is left.
- `PastYouTrend`'s `relative_effort_band` — shipped in the payload, rendered nowhere (already true
  before this slice; noted because the relative-effort cut invites deleting it).

## Files touched

**Deleted (16 source + 14 test + 2 controller tests)**: `components/card/{CardReveal,PackWrapper,
FeaturedCardHero}.tsx`, `components/dashboard/FeaturedKartuPanel.tsx`, `components/PersonaBar.tsx`,
`components/ConfettiBurst.tsx`, `components/temari/UnlockToast.tsx`,
`components/celebrations/AccessoryUnlockModal.tsx`, `components/trends/panels/{LoadTrend,VdotTrend,
PaceConsistencyTrend,PersonalBests}.tsx`, `components/plan/{SeasonTrack.tsx,StreakPanel.ts}`,
`components/me/SeasonStreakPanel.tsx`, `hooks/useDawnShift.ts`,
`app/Http/Controllers/Api/{CardSeenController,CardReplayController}.php`,
`app/Http/Controllers/Strava/ResyncActivityController.php`,
`app/Http/Controllers/Notifications/SendActivityNotificationController.php`, and every co-located
test plus `tests/Feature/Reveal/CardSeenTest.php`.

**Edited — frontend**: `layouts/{AppShell,BareShell,appLayout}.tsx`, `pages/{Home,Inbox,Plan,Profile,
Race,Trends}.tsx`, `pages/Home/helpers.ts`, `pages/Runs/{Show.tsx,useRunShow.ts}`,
`pages/Devtools/Design.tsx`, `components/inbox/InboxRow.tsx`, `components/home/WeekPlanWidget.tsx`,
`components/trends/panels/FitnessTrend.tsx`, `lib/{designTokens,shareCard}.ts`, `types/inertia.ts`
(+ their tests).

**Edited — backend**: `routes/web.php`, `Http/Controllers/{InboxController,ProfileController,
RaceController,RunController,TrendsController}.php`, `Services/Inertia/{GamificationProps,
SharedProps}.php`, `Services/Run/Story/{BriefingComposer,BriefingResult}.php`,
`Services/Run/Plan/CurrentWeekPlanBuilder.php`, `Notifications/AnalysisReadyNotification.php`,
`tests/Pest.php`, `tests/Unit/Architecture/{EveryClassHasATestTest,DesignTokenContrastTest}.php`.

**Styles / generated**: `resources/css/app.css` (dawn-shift block),
`resources/brand/grounds.json` (12 stale panel registrations).

**Docs**: `design-tokens.md`, `features/{cards-collection,dashboard,gamification,notification-inbox,
plan-periodizer,profile,race-projection,records,recaps,run-detail,run-qa,strava-connect,
targets-accessories,telegram-notifications,vibe-and-mood}.md`,
`architecture/{frontend-architecture,training-load-metrics}.md`,
`decisions/ink-grounds-derived-not-listed.md` (dated banner only — ADRs are immutable).

## Blockers

None encountered. `PP1` had already moved Onboarding to `BareShell`, so that row needed no work.

## Acceptance criteria

- [x] Every `cut-list.md` §1 row except `TemariProto`, Onboarding chrome and Accessories is either
      deleted or verified already done.
- [x] `TemariProto`, `build-mascot.mjs`, `lib/temariPose.ts` and every mascot call site untouched —
      `PP2`'s. (`AccessoryUnlockModal` rendered `TemariProto`; the whole file went, which the brief
      names as fine and expected.)
- [x] Every deleted component's co-located test deleted with it; no `EXEMPT` / `TS_EXEMPT` entry
      added, and the two orphaned `EveryClassHasATestTest` exemptions removed.
- [x] Every route deleted is unreachable, and no controller is left without a test.
- [x] No model, migration, DB column, job or domain service deleted.
- [x] `grounds.json` regenerated as the last step; `DesignTokenContrastTest` green both ways.
- [x] `check-doc-citations.php` run directly and green; every doc a deletion made wrong fixed in the
      same commit as that deletion.
- [x] No guard weakened. `check-entry-chunks.mjs`'s four hardcoded paths all still resolve; no budget
      moved.

## Coverage delta

Measured on this branch, `origin/epic/mobile-ux-port` vs the slice, same run configuration:

| | before | after |
|---|---|---|
| statements | 95.51% (4579/4794) | **97.25%** (4187/4305) |
| branches | 89.21% (3797/4256) | **90.68%** (3436/3789) |
| functions | 95.52% (1239/1297) | **96.86%** (1111/1147) |
| lines | 95.97% (4337/4519) | **97.62%** (3979/4076) |

Up on every axis, and no test was written to move it — 489 statements left the denominator and 392
of them were covered, so the deleted code was **80.2%** covered against a 95.51% baseline. Deleting
below-average-coverage code raises the average. The two visible drops are local and expected:
`Runs/Show.tsx` falls to 88.88% statements / 72.72% functions because the resync and send handlers
that its five deleted tests exercised are gone while the file's untested branches remain, and
`useRunShow.ts` holds 100% statements with branches at 84.61%. Both are `PS8`'s to raise when it
rebuilds the screen; neither is near the gate.

## Verification notes

Full ladder, in order, all green:

| step | result |
|---|---|
| `pest --group=structure` | 38 passed, 116 assertions |
| `pest --parallel --no-tia` | **3660** passed, 10897 assertions (was 3684 — 24 tests left with their surfaces) |
| `phpstan analyse --debug` | 0 errors |
| `pint` · `rector --dry-run` | clean · 0 changed |
| `tsc` | clean |
| `eslint --max-warnings 0` | clean |
| `npm run build` + `check:chunks` | within budget on all four routes |
| `npm run test:coverage` | 1916 passed, 97.25% statements |
| `check:palette` | 430 files, zero off-token utilities |
| `php scripts/check-doc-citations.php` | all citations resolve |

**Entry chunks — nothing re-baselined.** Deleting only shrinks bundles, and every route came in
under its previous number, so raising or lowering a budget would have been noise:

| route | `PP1` | `PP3` | budget |
|---|---|---|---|
| entry | 111.7 kB gz | 111.6 | — |
| Login | 146.7 | 146.4 | 160 |
| Home | 226.9 | 210.1 | 240 |
| Runs/Show | 230.3 | 221.9 | 245 |
| Profile | 201.5 | 195.4 | 230 |

**The doc-citation guard caught one unrelated drift** it would have blamed on this slice:
`run-qa.md` cited `routes/web.php#L224` in a 223-line file after the route deletions shifted it.
Repointed at `#L201`, the line `RunQuestionController`'s routes actually start on.

**One ordering mistake, caught by the ladder rather than by review.** Five `Runs/Show.test.tsx`
cases exercising the resync and send buttons were left behind when that row was cut, because the
narrow check run after that commit was the PHP suite and `tsc` — neither of which sees a Testing
Library query that no longer matches. `test:coverage` failed on them. Fixed before the dawn-shift
commit; the lesson is that a cut needs `vitest` on the touched page, not just a typecheck.

**No browser sweep.** This slice only removes; it introduces no new layout, and `PP1` already
verified the shell in a real browser. Each `PS` slice screenshots its own screen against `PP0`'s
reference captures, which is where a visual regression from a removed block would actually show.

## Open questions

1. **`AnalysisType::BriefingFeaturedKartuVoice` is now generating narration nobody can read.**
   `ai:daily-briefing` still keys a row off the featured card every morning, `SelfHealer` still
   resumes it, and the rule-based filler still fills it — all for a panel that no longer exists.
   Under `AiEnabled` that is a **real, recurring token cost for dead output**. It is out of `PP3`'s
   depth (P4 puts the whole pipeline in `W2`), but unlike the other `W2` orphans this one bills.
   Worth pulling forward if `W2` is far off.
2. **`streak_days` and `strainMonotonyTrend()` are computed nowhere now** but their metric
   definitions are documented as distinct concepts. If a later slice wants a day-grained streak
   back, git history has both the builder method and its two tests.
3. **`Runs/Show.tsx` is the file this slice thinned most** (four separate cuts landed in it). Its
   function coverage is now 72.72%, the lowest of any surviving page. `PS8` rebuilds it to the
   prototype's section list anyway, so raising it now would be work thrown away — but it should not
   be allowed to sit there past `PS8`.
4. **`records.md` now documents a feature with one thin surface.** If `PS10` decides the prototype's
   `ProgressionCard` is the only PR reading worth keeping, the pace-window PR categories become
   dead data and the note should shrink to a `W2` sweep line rather than stay a feature note.
