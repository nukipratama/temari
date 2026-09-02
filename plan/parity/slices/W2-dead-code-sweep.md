# W2 — Dead-code sweep

Wave 3's second slice, and the one the program has been deferring into since `PP3`. Every cut so
far removed a surface and left its backing code standing, by design: P4 scoped the screen slices to
"UI and routes now, backend later" precisely so a deletion could be reviewed as a deletion.

This is later.

**Ruled by the user 2026-09-02: cut code *and* schema**, on the reasoning that the `migrate:fresh`
planned for the epic's merge to `main` makes dropping dead schema free now and a real data migration
afterwards. That ruling turned out to buy less than it promised — see "The schema half is smaller
than the ruling assumed" below.

Two further scope calls, both ruled by the user on the same day and both maximal:

- **The whole dead `resources/brand/` preview layer goes**, not only the mascot and accessory
  chain that P10/P21 cut.
- **All seven test-only frontend modules go**, including `GradientText` and `HeroPanel`, which are
  documented design-system vocabulary.

## How the inventory was built

Three independent read-only sweeps — frontend orphans, PHP orphans, dead schema — run in parallel
against the whole tree, then every load-bearing claim re-verified by hand before it reached this
document. That second step was not ceremony: it changed the list four times, and the corrections are
recorded below rather than quietly folded in.

## `PP3`'s handoff list was three-for-five

`PP3` closed with nine items "deliberately left for `W2`, recorded so the sweep has a list rather
than a rediscovery". It is the best kind of handoff and it was still wrong twice, which is this
program's most durable lesson holding for the eighth slice running.

| `PP3` said | actually |
|---|---|
| `Session::flash('unlock', …)` — nothing reads the flash now | **correct.** `GrantEligibleUnlocksAction.php:86` still flashes it; no client reads `flash.unlock`. |
| `TrainingLoad::strainMonotonyTrend()` — nothing reads it now | **wrong. It is live.** `TrendRangeTool.php:66` calls it and ships the series in the tool payload every narrator run. Trends stopped *drawing* strain and monotony under P25; the AI kept reading them. **Kept.** |
| `PrCategory::efforts()`'s pace-window PRs — stored, no longer drawn anywhere | **wrong. They are consumed.** `PersonalRecords.php:103` writes them and `RiegelProjector` regresses the athlete's own Riegel exponent across them, converting their sec/km to elapsed time first. Not drawn is not the same as not read. **Kept.** |
| `readDawnShiftSurfaces()` and its `.mjs` callers degrade to an empty map | **correct, and now proven** rather than reasoned: called directly, it returns `{}`, and `paperGrounds()` yields 13 grounds with zero `surface · <bucket>` entries. Removing the function and its loop is a no-op by measurement. |
| `PastYouTrend`'s `relative_effort_band` — in the payload, rendered nowhere | **correct.** Shipped at `PastYouTrend.php:59`, typed at `inertia.ts:356`, and referenced on the client only by five test fixtures. |

The two wrong rows share a shape worth naming: **both were read as dead because their *screen* was
cut.** A metric can lose its chart and keep its consumer, and in this app the second consumer is
usually a narrator tool rather than a component. "Nothing draws it" is not evidence.

`AkuProfileVoiceNarrator::personaMix()`, which `PP3` flagged as "possibly a keep", is a keep for the
same reason. So is `RelativeEffort`, which `cut-list.md` §1 lists as cut: the cut was its UI, and
`EffortContextTool` feeds it to three live narrators.

## What goes

### 1. The featured-kartu narration pipeline

The one item on the list that **bills**. `PP3` flagged it as such and recommended pulling it forward
if `W2` was far off. It was not far off, so it lands here.

`ai:daily-briefing` resolves a featured card per active user every morning and dispatches
`AnalysisType::BriefingFeaturedKartuVoice` against it, keyed on the card id so it re-bills whenever
the pick changes. `SelfHealer` resumes it, `BackfillAgeGate` exempts it, and
`RuleBasedNarrationFiller` fills it past the cost ceiling. The panel it narrates —
`FeaturedKartuPanel` on Today — was deleted by `PP3` under P29. The only surviving frontend
reference is the generated `AnalysisType` union in `types/generated.ts`, which regenerates.

Deleting it is a whole-pipeline change, which is exactly why P4 kept it out of a UI slice:

- **four classes**: `ResolveFeaturedKartuAction`, `BriefingFeaturedKartuVoiceNarrator`,
  `AnalyzeBriefingFeaturedKartuVoiceJob`, and `FeaturedCardTool` (whose sole consumer is the
  narrator's own toolbox)
- **six `AnalysisType` arms**: the case, the import, `jobClass()`, `discriminatorRules()`,
  `subjectType()`
- **five pipeline wirings**: `SelfHealer` (the resume call and its `match` arm), `BackfillAgeGate`,
  `AnalysisSubjectAuthorizer`, `RuleBasedNarrationFiller` (arm + private method),
  `DailyBriefingCommand` (the injected action and the dispatch block)
- **the demo seeder's mirror of the same dispatch**
- **`config/azure_openai.php`**'s deployment mapping
- **three dedicated test files** plus **ten more** that assert on the case

`AZURE_OPENAI_BRIEFING_FEATURED_KARTU_VOICE_DEPLOYMENT` is one of CLAUDE.md's four grandfathered
non-English identifiers, kept because it is *deployed*. Removing the config line is safe with the
variable still set on the host — it simply stops being read. The host-side cleanup is noted in the
PR body for the user to apply, not done here. `W6` no longer needs to rename it; it can be deleted
outright, which is a simplification of `W6`'s remaining scope.

### 2. The accessories equip chain, end to end

`PP2` deleted the Accessories page and controller. What survived is a shared prop that is computed,
cached and serialised on **every page load** for a value nothing reads:

`GamificationProps::equippedAccessoriesFor()` → `SharedPropCacheKey::EquippedAccessories` →
`EquippedAccessories` service → `user_unlocks.equipped`. The service's own docblock already concedes
the position ("this still backs the `equippedAccessories` shared prop, which nothing reads and
nothing can now change"), and `inertia.ts` carries the type with no component destructuring it.

`user_unlocks.equipped` has **no production write path at all** — the only writers left are
`DemoRunSeeder` and a `UserUnlockFactory` state. The rest of `user_unlocks` stays: lifetime and
season unlocks are live.

### 3. The card-reveal remnant, and a bug it was hiding

`PP3` removed the modal, its two API routes and both controllers, and kept
`users.pending_reveal_card_id` for this slice. `RunCardFactory::queueRevealFor()` still writes it on
every card that beats the user's previous rarity, and **nothing has ever cleared it** since the
reveal endpoint went.

So in production the column is set once, on a user's first sufficiently-rare card, and then
`queueRevealFor()`'s own `!== null` guard makes every later call a no-op forever. That is a live bug
today; it is moot here only because the whole path goes. Recorded because it is the sharpest
available argument for not leaving write-only state standing "for later".

### 4. Seven test-only frontend modules

Imported by nothing but their own co-located test. Each verified by hand — every remaining mention
in source is a comment, not an import.

| module | why it is dead |
|---|---|
| `race/CtlTrendChart.tsx` | `PS5` cut Race's fitness chart under P26; `PS6` built Trends' `FitnessPanel` on `LineChart` directly rather than reusing this. Predicted by `cut-list.md` §1, which corrected itself on 2026-09-01. |
| `ui/badge.tsx` | **`F3`'s `Chip` → `badge` swap ended up reversed.** shadcn's `badge` has no importer; `Chip` has four. Nothing recorded the reversal — the screen slices simply reached for `Chip`. |
| `ui/HeroPanel.tsx` | `PS8` and `PS10` moved their heroes off the sky-gradient panel onto the prototype's card tone. |
| `ui/GradientText.tsx` | No screen renders gradient text. |
| `ui/GoalCard.tsx` | Orphaned by P24's season rework. |
| `dashboard/ExpandableQuote.tsx` | Superseded by `ReadMoreToggle`. |
| `lib/seasonPhase.ts` | Orphaned by `PS4`'s timeline. |

`GradientText` and `HeroPanel` are named in `docs/design-tokens.md`, `docs/voice-and-tone.md`,
`CLAUDE.md` and the temari skill. **Two of those are path citations**, so deleting the files without
the doc edits reddens `repo-guards` — the failure mode that merged three PRs red after `T2`. The doc
edits land in the same commits as the deletions.

`badge.tsx` also holds **five `grounds.json` panel registrations** plus `__belowAa` ledger entries
for its `destructive` variant. `DesignTokenContrastTest` fails closed on a registration that paints
nothing, so those come out surgically in the same commit. Never a re-sort.

### 5. The dead `resources/brand/` preview layer

No runtime code, npm script, composer script, CI job or test reads any of it. Only
`build-tokens.mjs`, `grounds.mjs`, `grounds.json` and `build-icons.mjs` are live, the first two
pinned by `build-tokens-dark.test.ts` and `DesignTokenContrastTest`.

- **Nine generators**: `build-mascot`, `build-accessories`, `build-cards`, `build-screens`,
  `build-empty`, `build-marks-sporty`, `build-directions`, `build-og`, `apply-pewter`.
- **Five output directories**: `accessories/` (25 SVGs), `mascot/` (16), `cards/`, `marks/`,
  `marks-sporty/`.
- **Nine preview HTML pages**, including the 119 kB `directions.html`.
- **`tokens.css` and `tokens.html`** — `T4`.

`T4`'s premise needs one correction. It reads as "stale generator output from a dead script", but
`css()` reads the live `COLOR` map: run the generator today and it emits current Pewter. The
committed files are stale only because nobody has run it since the palette changed. The dead thing
is the **output half** of `build-tokens.mjs` — `css()`, `html()`, `audit()` and the CLI write block,
whose header still says "Drop into `resources/css/app.css` @theme when Phase 2 S2.1 lands." That
landed. The file's live exports stay untouched.

The §6 amendment of 2026-08-31 ruled `build-mascot.mjs` **kept**, reasoning that
`build-accessories.mjs` imports it. That reason dissolves when `build-accessories.mjs` goes too, so
this slice supersedes that half of the amendment. Its other half — that these are brand generators
rather than UI — stood only while something consumed their output. Nothing does.

**Beyond the enumeration the user ruled on**: `resources/brand/prototypes/trends/` is a *second*
prototype, older and unrelated to the frozen mobile-UX one — a standalone Vite app, 38 tracked
files and 1.2 MB with its own `package-lock.json`. It was not among the five directories named when
the scope question was put, so it is called out here and in the PR body rather than folded in
silently.

### 6. Three unused npm dependencies

`clsx` (decision 3 adopted it; nothing imports it directly, and it survives transitively under
`class-variance-authority`), `html-to-image` (`ShareCardModal` uses native `canvas.toBlob`), and
`tw-animate-css` (imported nowhere, and not `@import`ed in `app.css` either).

Composer's 26 packages are all live; nothing to prune there.

### 7. `PP3`'s three correct leftovers

`Session::flash('unlock')`, `readDawnShiftSurfaces()` with its now-empty loop in `paperGrounds()`,
and `PastYouTrend`'s `relative_effort_band` with its type and five test fixtures.

## The schema half is smaller than the ruling assumed

The ruling anticipated dropping dead tables while the `migrate:fresh` made it free. **There are no
dead tables.** Every table in `database/migrations/` and `database/migrations/analytics/` has live
non-test call sites, including every named suspect — `run_cards`, `user_unlocks`, `seasons`,
`season_goals`, `race_goals` and the rest. `telegram_deliveries` was already dropped in July.

What is dead is two columns:

| column | why |
|---|---|
| `users.pending_reveal_card_id` | the cut card-reveal flow |
| `user_unlocks.equipped` | the cut Accessories equip surface |

Persona mix, relative effort and dawn-shift are backed by **no persisted column at all** — all three
are computed on the fly or frontend-only, so there was never any schema to sweep for them.

Both drops are ordinary `dropColumn` migrations following this repo's convention, which is a new
migration per change and never an edit in place —
`2026_08_29_132609_drop_distance_band_and_pace_band_from_planned_sessions_table.php` is the
same-epic precedent. **App code is removed before or with the drop**, never after, so a stale deploy
never runs a write against a missing column.

So the schema half is small and safe either way. The ruling costs nothing, but it should be recorded
that it did not unlock what it was made to unlock.

## Acceptance criteria

1. `ai:daily-briefing` dispatches no featured-kartu analysis, and the enum case no longer exists.
2. No shared prop is computed for a value the client cannot read.
3. Every deleted module takes its co-located test, and every doc citing a deleted path is fixed in
   the same commit — `php scripts/check-doc-citations.php` green, run directly.
4. `grounds.json` carries no registration for a deleted component; `DesignTokenContrastTest` green.
5. The two dead columns are dropped, with their writers removed in the same change.
6. `./vendor/bin/sail composer check` green (`--no-tia` on pest), plus `npm run test`,
   `check:palette` and `check:chunks`.

## Verification notes

- **`readDawnShiftSurfaces()`'s removal was proven, not argued.** Called directly it returns `{}`,
  and `paperGrounds()` yields the same 13 grounds, named identically, before and after.
- **`build-tokens.mjs` was exercised after the cut**, not just parsed: `COLOR.horizon` is still
  `#ade047`, `horizon-ink` still derives to `#546d23`, `DARK_INK.horizon` is still `#ade047`
  (`PP5`'s fix intact) and `RARITY_INK.legendary` is unchanged.
- **The chunk budgets did not move by a single byte** — every one of the eleven routes reports the
  same gzipped size as before the sweep. That is the strongest available evidence that the seven
  deleted modules were genuinely unreachable: Rolldown was already tree-shaking them out, so
  deleting them changed the source tree and not the bundle.
- **Two guards fired and both were right.** `check-doc-citations.php` caught nine dead citations
  across seven documents, in three separate rounds. `DesignTokenContrastTest` caught `horizon/0.06`
  — painted only by `GoalCard.tsx:29` — and correctly left the neighbouring `horizon/0.07` and
  `/0.08` alone, which still have painters.
- **PHPStan overruled a judgement call, correctly.** See open question 2.
- **`demo:seed` still converges** after losing three blocks (the re-equip sweep, the reveal queue
  and the featured-kartu dispatch): 127 runs, 27 snapshots, 25 inbox rows, no errors.
- Full PHP suite 3605 green, frontend 1825 green across 214 files, `check:palette`,
  `check:chunks`, `check-doc-citations.php` and `check-see-references.php` all green, run directly
  rather than through `composer check`, which cannot complete locally.

## A citation that broke three times

`docs/architecture/ai-narration-internals.md:94` cites `DemoRunSeeder.php` **by line number**, and
this slice removed three separate blocks from that file. The citation broke on each one, and the
guard named the symbol's new location every time. Worth recording because a `#Lnn` citation into a
file a slice is actively editing will drift once per edit, and `composer check` is not where you
find that out.

## Open questions

1. **`resources/brand/prototypes/trends/dist/` still exists on disk**, 12 gitignored build
   artifacts belonging to the prototype whose source this slice deleted. They are untracked, so
   they do not affect the repository, and they were left rather than removed under the standing
   rule against discarding working-tree files this session did not author. One `rm -rf` closes it.

2. **`build-tokens.mjs` still exports `RADIUS`, `SHADOW`, `FONT`, `SPACE` and `PAD` with no
   consumer.** They were only ever read by the emit half this slice deleted. They are kept because
   `docs/design-tokens.md:6` names this script as the owner of the token set, and stripping the
   scale maps would make that claim false — but they are now literals duplicated in `app.css` with
   no derivation, no consumer and no test pinning them against it, which is exactly the drift
   `DesignTokenMirrorsTest` exists to prevent elsewhere. **`W4` should decide** whether the doc
   claim or the maps are what changes.

   The same reasoning was tried on `AnalysisSubjectAuthorizer::authorizeDiscriminator()` and
   **PHPStan rejected it**: always-true match, unreachable throw, no side effects. The argument for
   keeping it — that its exhaustive `match` forces a future resource-keyed type to answer the
   ownership question — did not survive the observation that `AnalysisTypeTest` already enforces
   exactly that, and better. It was deleted. Worth noting that the type checker, not review,
   is what caught the rationalisation.

3. **`AZURE_OPENAI_BRIEFING_FEATURED_KARTU_VOICE_DEPLOYMENT` can be dropped from the prod host.**
   Nothing reads it; leaving it set is harmless. In the PR body for the user to apply at deploy.

4. **`W6` shrinks again.** It was to rename that env var; it can now delete it. Of the three
   persisted Indonesian values, `briefing_featured_kartu_voice` is gone outright, leaving
   `aku_profile_voice` and the `rute` share-card token — both ordinary renames after the
   `migrate:fresh`.

5. **A `W6` lead found in passing, not touched**: three comments still describe banners as
   `"Temari lagi istirahat"` / `"lagi dijeda"` (`inertia.ts:116`, `inertia.ts:120`,
   `AiProps.php:43`), and `BriefingComposerTest` seeds `'Pagi yang oke'`. The banners themselves
   are English; these are drift from before the 2026-08-09 swap.
