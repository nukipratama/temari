---
title: Profile
description: The runner's identity page — Temari's profile voice, lifetime stats, PR progression charts, Strava status
tags: [feature, profile]
status: living
reviewed: 2026-10-05
code_refs:
  - resources/js/pages/Profile.tsx
  - app/Http/Controllers/ProfileController.php
  - resources/js/components/temari/AnalysisStatus.tsx
  - resources/js/components/profile/ProfileHero.tsx
  - resources/js/components/profile/TimeInZoneBar.tsx
  - resources/js/components/profile/SeasonCard.tsx
  - resources/js/components/profile/PaceTargetsCard.tsx
  - resources/js/components/profile/ProgressionCard.tsx
  - resources/js/components/profile/JourneyChart.tsx
  - resources/js/components/temari/MascotWatermark.tsx
  - resources/js/components/UserAvatarLink.tsx
  - app/Services/Run/Metrics/TimeInZoneSummary.php
  - app/Services/Run/Metrics/VdotEstimator.php
  - app/Enums/PerformanceEvidenceKind.php
  - app/Models/PerformanceEvidence.php
  - app/Models/FitnessAnchor.php
  - app/Actions/Run/Metrics/ResolveHardEffortsAction.php
  - app/Http/Controllers/PerformanceEvidenceController.php
  - database/migrations/2026_10_01_000100_create_performance_evidence.php
  - database/migrations/2026_10_01_000200_create_fitness_anchors.php
  - routes/web.php
  - app/Actions/Run/Metrics/EstimateThresholdAction.php
  - app/Services/Run/Metrics/TrainingPaceCalculator.php
  - app/Services/Run/Plan/WeekSessionTypesBuilder.php
  - app/Services/Gamification/SeasonPayloadBuilder.php
---

# Profile

The Profile page (`/profile`) is the runner's about-me: who they are, how Temari sees them, their lifetime totals, and their PR progression over time. Server entry is [ProfileController](app/Http/Controllers/ProfileController.php) (`__invoke`), rendering the [Profile](resources/js/pages/Profile.tsx) page.

**Navigation:** `route('profile')` → `/profile`. Named route: `profile`. There is no bottom-nav "Me" tab — [UserAvatarLink](resources/js/components/UserAvatarLink.tsx) links the avatar in [MobileTopBar](resources/js/components/MobileTopBar.tsx) straight to Profile from every bottom-nav screen. Profile is itself a **pushed screen**: its topbar carries a back chevron to Today and a gear to Settings, and it renders no bottom nav. Profile and Settings stay two separate routes/controllers, not a merged `/me?segment=` route. There is no `/aku` route, and the `/profil` redirect was deleted in `C1` along with every other legacy redirect. The segmented `MeTabs` nav that used to sit atop both pages was cut by the parity program's `PP1`, and the `/accessories` route, controller and page by `PP2`.

## System dependencies

- **AI narration** — `profileVoice` (`ProfileVoice`) is an `Analysis` row from the [[ai-pipeline]]. It is the page's only narrated block.
- **Gamification** — the `PersonalRecord` rows behind the progression charts, and the `Season`/`SeasonGoal`/streak data behind the Season & streak panel, come from [[gamification]].
- **Settings** — the Telegram toggles, HR-zone entry, and account deletion moved to the [[settings]] hub; Profile links to it.
- **Data model** — `PersonalRecord` shape in [[data-model]].

## Identity + What Temari says about you

A "Profile" eyebrow sits over a "{firstName}, / *your story.*" headline with the athlete's avatar circle beside it. Below, [ProfileHero](resources/js/components/profile/ProfileHero.tsx) has Temari as a watermark ([MascotWatermark](resources/js/components/temari/MascotWatermark.tsx)), posed to the daily vibe through the `mood` prop, beside **"★ What Temari says about you"** — the AI profile voice (`profileVoice`), rendered through [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx) in the `.narration` prose register. An "Est. {date}" line carries the first-run date at every width; a "With Temari since" block is revealed to its right only above 900px (the prototype's one visibility-toggled element). Strava status (`identity.strava_connected`) shows as a "Reconnect" action when revoked.

The panel is **card-toned with a horizon halo**, not a sky-gradient panel — `PS10` matched the prototype's own `bg-card` hero, as `PS8` did on activity detail.

This is the merged profile voice: it reads who the runner is from their 12-week mood mix and backs that reading with their lifetime numbers, in one billed call ([ProfileVoiceNarrator](app/Services/AI/Narrators/ProfileVoiceNarrator.php) carries `get_persona_mix` alongside `get_lifetime_stats`, `get_training_paces` and `get_progression_signal`). Server side, `ProfileController::resolveProfileVoice` looks up the `ProfileVoice` analysis keyed by **ISO week** (`isoFormat('GGGG-[W]WW')`) and returns `Analysis::toPayload`. The numbers on the page are live; the prose is refreshed once a week by `ai:weekly-profile` (`invalidate: false`, so the week key is the refresh) or on demand via "Reread". See [[recaps]] and [[ai-pipeline]].

**The evidence it may quote is bounded by the schema, not by the prompt's prose.** The prompt has asked for "one, at most two" numbers since the merge, and live output stacked four of them beside the mood percentages. The fix is [TrendReadNarrator](app/Services/AI/Narrators/TrendReadNarrator.php#L114)'s `reading` move applied here: the response schema carries two required `evidence_*` slots ahead of the paragraph ([ProfileVoiceNarrator](app/Services/AI/Narrators/ProfileVoiceNarrator.php#L180)), the model fills them with the already-formatted figures before it writes a word, and the paragraph may quote nothing but those two — so a third figure has no field to arrive in. The slots are never rendered; only required. **A schema slot is a commitment, not a bound** — live output on 2026-09-10 filled both slots correctly and then quoted a `weekly_streak` figure neither one held, because the prompt carved the streak out as free to mention. The carve-out is gone (the streak is a slot candidate like any other) and the bound is now checked in code: every figure the paragraph quotes is extracted and matched against the two slots plus the mood mix the prompt exempts as *claim* rather than evidence, including the tool's lookback window, its half-window and total run count ([`figureComplaint`](app/Services/AI/Narrators/ProfileVoiceNarrator.php#L204), [QuotedFigures](app/Services/AI/Narrators/QuotedFigures.php#L32)). A leaked figure is handed back to the model once with the offending numbers quoted, and a second leak throws, so the block fails honestly rather than shipping a number the athlete cannot trace ([StructuredChatCaller](app/Services/AI/StructuredChatCaller.php#L149)). The same output printed `1607 seconds` where [ProgressionCard](resources/js/components/profile/ProgressionCard.tsx#L115) prints `26:47` for the same delta, so [ProgressionSignalTool](app/Services/AI/Agent/Tools/ProgressionSignalTool.php#L85) now returns `delta_formatted` beside the raw `delta_sec` and its description makes the formatted one the only quotable form.

## Stat row

A horizontally scrolling row inside the hero: **Total km**, **Total runs**, **Longest run** (its value carries the unit, `42.6 km`, like **Threshold**'s `/km`, so the label fits one line on phones), plus **VDOT** and **Threshold** when the athlete has a VDOT-eligible PR. The controller delegates to [LifetimeStats](app/Services/Run/LifetimeStats.php), the same service `/calendar` uses: one aggregate query over `ActivityDetail` (`SUM(distance)`, `MAX(distance)`, `MIN(start_date_local)`) plus `user->activities()->count()` for the run count, converted to km and cached per user for 5 minutes. `/profile` maps its `longest_km` onto the `longest_run_km` prop; the page renders **Total km** and **Longest run** at 1dp. The service rounds the total to 1dp and the longest run to 2dp, so the tile drops the second decimal.

Sharing `/calendar`'s cache means the totals can trail a just-ingested run by up to the TTL, the same window `/calendar` has always had.

## Fitness — VDOT, threshold pace & training paces

When the runner has a VDOT-eligible PR, the hero stat row grows two more tiles (**VDOT**, **Threshold**) and [PaceTargetsCard](resources/js/components/profile/PaceTargetsCard.tsx) renders below the season card as a **ladder**, slowest rung first: **easy**, **long run** (marathon pace, named by the session that uses it), **tempo**, **interval**, each one a lowercase label, the pace-per-km in big mono via `formatPace`, a hint, and a thin bar. The bar places the pace between the slowest and the fastest of the four, floored at `MIN_FILL` so the slowest rung is still a bar rather than an empty track, and every bar fills to the same width when all four paces are identical.

The ladder replaced a horizontal rail whose four labels had to be measured against the live rail width and pushed apart when two paces crowded each other — a `ResizeObserver` plus a `document.fonts.ready` remeasure, and a `layoutPaceLabels` collision pass in a `lib/paceRail` module. Stacking the rungs vertically gives each label a row of its own, so all of that measuring went away with the rail (and the module with it): four numbers you would use on a run, not a chart.

**What the hints say.** `ProfileController::fitness` ships `week_sessions` beside the paces — one entry per training day of the current week, `{weekday, session_type, distance_km, is_today}`, built by [WeekSessionTypesBuilder](app/Services/Run/Plan/WeekSessionTypesBuilder.php). It reads the same rows over the same trailing window [CurrentWeekPlanBuilder](app/Services/Run/Plan/CurrentWeekPlanBuilder.php) does and sizes each day through [PlanRenderer::sessionDistanceKm](app/Services/Run/Plan/PlanRenderer.php#L201), the one helper [PlanRenderer](app/Services/Run/Plan/PlanRenderer.php)`::dayPayload` sizes Home's days with, so a distance on this card cannot disagree with the one Home shows for that day. Rest days are dropped: no pace answers to them. The card maps `easy`→easy, `long`→long run, `tempo`→tempo, `interval`→interval, and renders the days that ask for each pace (`mon, sat`, or `wed · 10k` when a single day owns it, `none this week` when none does). With no plan at all each rung falls back to fixed copy — "most of your runs", "long steady efforts", "comfortably hard, 20–40 min", "short hard reps". The rung matching **today's** session type is the accented one; a rest day, a day off-plan, or a `race` day accents nothing. Which entry is today is decided server-side by `is_today`, against the same `$today` the week itself is built from — the card used to derive it from `new Date()`, so a viewer whose clock sat on the other side of midnight from the server saw the accent on the wrong rung.

**Where the numbers came from** reads as chips under the ladder rather than a sentence: `from 5 km pr · aug 28`, plus `stale` only when the estimate has nothing recent behind it, plus `tempo + interval from …` only when the quality anchor diverges from the base one. With no `vdot_source` there are no chips.

`ProfileController::fitness` builds the `fitness` prop from [VdotEstimator](app/Services/Run/Metrics/VdotEstimator.php)`::estimate`, [EstimateThresholdAction](app/Actions/Run/Metrics/EstimateThresholdAction.php)`::__invoke` and [TrainingPaceCalculator](app/Services/Run/Metrics/TrainingPaceCalculator.php)`::fromVdotResult`; `fitness` is `null` (and the extra tiles don't render) until a qualifying provisional PR or confirmed performance exists.

These are the same estimators [ProfileVoiceNarrator](app/Services/AI/Narrators/ProfileVoiceNarrator.php) calls via [TrainingPacesTool](app/Services/AI/Agent/Tools/TrainingPacesTool.php) to narrate pace targets in prose — the numbers reach the user both ways, tabulated here and spoken in the hero voice above.

### Confirmed performances and the provisional guide

Without confirmed evidence the guide is provisional: [VdotEstimator](app/Services/Run/Metrics/VdotEstimator.php) reads hard efforts the athlete has not confirmed: distance records that cover essentially the whole run ([ResolveHardEffortsAction](app/Actions/Run/Metrics/ResolveHardEffortsAction.php)). A fast segment inside a longer run never counts, and neither does the Strava workout tag. The [FitnessAnchor](app/Models/FitnessAnchor.php) is captured once, with the first estimate, and only its capture time is read: rises resting on unconfirmed records are replayed from it at up to 1 VDOT a week, while confirmed rises and every drop apply at once. Sustained evidence of at least 3 km is required. The full model is [[supported-race-time-from-recent-efforts]].

The authenticated `POST /fitness/evidence` route ([PerformanceEvidenceController](app/Http/Controllers/PerformanceEvidenceController.php)) records a runner-confirmed race or purposeful test. It accepts a qualifying distance from 1 km through marathon, elapsed time, performance date, and optional owned activity or race-goal references. Confirmation time is stored separately from performance date, so a later confirmation cannot rewrite what a historical plan knew. Repeating confirmation for the same activity returns its first saved details and does not move that confirmation date. A race outcome confirmed on the Race page takes the same validated path through [PerformanceEvidenceRecorder](app/Services/Run/Plan/PerformanceEvidenceRecorder.php) ([[a-race-outcome-is-confirmed-not-assumed]]).

Confirmed and unconfirmed efforts from the last 16 weeks form one pool, newest per 10% distance band; a confirmed result replaces the same run's unconfirmed record. The supported VDOT is read at the goal race distance (10K without one) from the efforts closest to it. When the recent confirmed efforts it could rest on differ by at least 10% in VDOT, confidence is `conflicting`. With nothing recent, the newest older effort remains available with `stale` confidence. Confirmed results inside the last three months and at or under 10 km may support threshold and interval paces; the 3 km minimum must still be represented. Controlled quality sessions that were prescribed and successfully completed are counted as corroboration, but never act as a maximal test.

The response to confirmation includes `fitness.vdot`, `fitness.vdot_source` (category, date, confidence, stale state, evidence id, evidence kind and distance, corroborating quality count, plus quality source metadata), and the four `training_paces`. The same source and freshness metadata is available on the Profile prop for the later evidence UI. If a pace changes by at least five seconds per kilometre and planned future sessions exist, the deterministic [Periodizer](app/Services/Run/Plan/Periodizer.php) refreshes those planned rows directly; the endpoint does not request narration. Settled and pinned rows stay fixed, and recommendation revisions/views remain as the history of what was previously shown.

### What counts as a threshold session

[EstimateThresholdAction](app/Actions/Run/Metrics/EstimateThresholdAction.php) reports the median best-sustained pace across the athlete's quality sessions in the trailing 60 days. Two things decide which sessions those are, and both were wrong until measured against a real athlete's 60 days.

**The filter reads Z4+, not Z3+.** Z3 begins near 80% of max HR, which is where a runner with little genuine easy running spends most of an ordinary day. A 30% *Z3+* bar admitted **30 of 38 runs**, including an easy 10 km at 7:31/km that logged 84.3% Z3+. The same 30% bar on *Z4+* admits **8**, and the split is clean: everything accepted ran 6:26/km or faster, everything rejected 6:43/km or slower. `StreamSummary::thresholdZoneShare()` is deliberately separate from `hardZoneShare()`, which has ten other callers and keeps its Z3-inclusive meaning.

**A 20-minute window counts.** The windows are tried longest-first, so a real hour of threshold work still wins where it exists. But quality sessions for this athlete ran **18 to 37 minutes**, and requiring 30 minutes discarded most of them — including a 28-minute 5 km time trial, the single strongest piece of evidence on record. The estimate was then drawn from slower, longer runs.

Together these produced **6:56/km at "high confidence" from 23 samples** for an athlete who had just run 5 km at 5:34/km. The figure is display-only (`ProfileController` is its one consumer, and no prescribed pace reads it), so it never distorted training — but it sits beside VDOT on the Profile, and a wrong number presented confidently is worse than no number.

### Endurance and quality read different evidence

[TrainingPaceCalculator](app/Services/Run/Metrics/TrainingPaceCalculator.php) derives easy and marathon pace from `vdot`, then threshold and interval pace from `quality_vdot`. A deleted or corrected run drops out of the pool and the records [PersonalRecords](app/Services/Run/Metrics/PersonalRecords.php)`::rebuildForUser` rebuilds, so a guide resting on it falls at once.

Quality reads recent short evidence: confirmed results when the athlete has any, distance records otherwise. A shorter confirmed result may keep quality VDOT at or below its sustained companion, but cannot raise it above that sustained evidence. Without a sustained confirmed result there is no confirmed anchor, and without a recent qualifying quality result the quality VDOT equals the endurance VDOT.

Both performance date and confirmation timestamp are checked against a caller-supplied `$asOf`. [TrainingBaseline](app/Services/Run/Plan/TrainingBaseline.php) threads its own date through, so [ComplianceScorer](app/Services/Run/Plan/ComplianceScorer.php) and [SeasonSummaryBuilder](app/Services/Run/Plan/SeasonSummaryBuilder.php) judge old weeks only by evidence and provisional snapshots available then, consistent with [[a-day-is-scored-when-it-is-run]].

## Time in zone · last 12 weeks

**P13.** [TimeInZoneBar](resources/js/components/profile/TimeInZoneBar.tsx) draws a segmented Z1-Z5 bar and a dot legend in the hero slot the behavioural persona mix used to occupy (`PersonaBar` and the `personaMix` prop were cut in `PP3`). The percentages come from [TimeInZoneSummary](app/Services/Run/Metrics/TimeInZoneSummary.php), which sums the per-run `time_in_zone_min` that [StreamAnalysis](app/Services/Run/Ingest/StreamAnalysis.php) already writes onto `activity_details.stream_summary` across the trailing 12 weeks and normalises them. Zone colours and labels are the shared `HR_ZONE_COLORS`/`HR_ZONE_LABELS` in [chartTokens](resources/js/lib/chartTokens.ts), the same pair the [[settings-hr-zones]] editor names its bands with.

The whole block is absent — bar, legend and label — when no run in the window recorded heart rate, rather than drawing an empty rail. `ProfileVoiceNarrator::personaMix()` and `PersonaMixTool` survive as narration context for the hero voice: `W2` verified both are live and kept them.

## Journey (progression)

When `progressionByCategory` is non-empty, [ProgressionCard](resources/js/components/profile/ProgressionCard.tsx) renders distance pills (5K / 10K / HM / FM), a "now … from then …" readout, the gap as a quote, a total chip and the goal chip, and [JourneyChart](resources/js/components/profile/JourneyChart.tsx) — an inline-SVG polyline with a fatter marker on the PR and a tappable tooltip per point. The series are built server-side by `ProfileController::buildProgressionByCategory` via `ProgressionSeriesBuilder`, over the four `PROGRESSION_CATEGORIES`.

**Progress has a direction.** The readout, the quote and the total chip all read one figure the server computes once per distance ([`ProgressionSeriesBuilder::progress`](app/Services/Run/ProgressionSeriesBuilder.php#L125)): the best time in the first four weeks of the 26-week lookback against the best in the last four, returned as a `relation` word (`faster` / `slower` / `flat`, flat being a change under 1% of the earlier time) beside an unsigned `delta_sec`. When either window has no run at that distance there is no figure, and the card draws only the chart and any goal chip. The card says "faster" only when the relation is faster ([ProgressionCard](resources/js/components/profile/ProgressionCard.tsx#L34)); it used to print the spread between the slowest and fastest weekly best as "faster", so a regression read as a gain. [ProgressionSignalTool](app/Services/AI/Agent/Tools/ProgressionSignalTool.php#L78) reads the same figure, ranks distances by relative improvement so a longer distance cannot win on absolute seconds, and hands the model the relation word, never a signed number.

The pills only offer distances the athlete actually has times at. The prototype draws all four because it has no data to be missing; offering a distance with nothing behind it would be a control that cannot work. The card opens on the active race's distance (the one series carrying `goal_sec`), else on the longest distance with a progress figure, else on the longest distance; a pill the athlete taps then holds for the visit ([ProgressionCard](resources/js/components/profile/ProgressionCard.tsx#L58)). `PS10` replaced the Chart.js `ProgressionChart` here — the prototype draws a compact journey line, not an axis-and-grid chart — which orphaned that component; `LineChart` itself survives, still lazy-loaded by Trends and Race.

## Race and season

The race row reads the app-wide `activeRace` shared prop
([GamificationProps](app/Services/Inertia/GamificationProps.php)) rather than a page prop of its
own: with a race set it shows name, distance, date and a days countdown; without one, the "Got a
race coming up?" prompt. Both link to `/race`.

**P24.** [SeasonCard](resources/js/components/profile/SeasonCard.tsx) replaces `SeasonStreakPanel`'s
five-row layout (cut in `PP3`) with the prototype's small card: the current phase and date range,
a segmented phase bar, and **one** goal progress line — the first goal still open, so the line
tracks what is actually being worked toward. Phases are derived by `phasesOf` in
[lib/plan](resources/js/lib/plan.ts), shared with Plan's own season header, over the week list
[SeasonSummaryBuilder](app/Services/Run/Plan/SeasonSummaryBuilder.php) builds; the goals come from
[SeasonPayloadBuilder](app/Services/Gamification/SeasonPayloadBuilder.php)`::profileSeasonPayload`, which sends the season dates and goals but not the Plan-only fields or the season record.

The controller resolves the season with `SeasonService::peekCurrent()` — a read-only counterpart to
`ensureCurrent()` that returns the current season **if one already exists**, never creating one,
because visiting Profile must not trigger the season-creation side effects a Plan page load does.
With no season, the card shows a "start one on Plan" CTA. The streak half of the old panel does not
come back: P27 cut the day-grained streak readout, and the week streak surfaces on Trends.

## Not on this page

PRs surface only as the progression charts above; the Personal Bests panel that used to list them on `/trends` was cut in `PP3` ([[records]]). Accessories are not rendered here either — `PP2` deleted the page that drew them, and the unlock catalog behind it has since been removed outright (see [[gamification]]).

## Settings

Profile carries no settings section of its own; the Telegram notification panel and HR-zone entry live on the [[settings]] hub instead. Settings is reachable from the gear in this screen's topbar ([MobileTopBar](resources/js/components/MobileTopBar.tsx)). Log out moved off the old avatar dropdown (which no longer exists) into a row at the bottom of Settings' Account section.

## Notes / gotchas

- `profileVoice` is keyed **per ISO week**, and `ProfileController` must compute that key the same way `WeeklyProfileCommand` and `DemoRunSeeder` do. `resolveProfileVoice` always returns a payload — `Analysis::toPayload(null, …)` stages a `pending` one when no row matches — and a plain `pending` block renders nothing at all, so a key mismatch shows up as a silently empty hero quote rather than an error.
- The voice block leans on the same [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx) state machine as the rest of the app — see [[ai-pipeline]] and [[data-model]] (`Analysis`, `PersonalRecord`).
