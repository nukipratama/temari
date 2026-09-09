---
title: Profile
description: The runner's identity page — Temari's profile voice, lifetime stats, PR progression charts, Strava status
tags: [feature, profile]
status: living
reviewed: 2026-09-01
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
  - resources/js/components/temari/FaceIcon.tsx
  - resources/js/components/UserAvatarLink.tsx
  - app/Services/Run/Metrics/TimeInZoneSummary.php
  - app/Services/Run/Metrics/VdotEstimator.php
  - app/Actions/Run/Metrics/EstimateThresholdAction.php
  - app/Services/Run/Metrics/TrainingPaceCalculator.php
  - app/Services/Gamification/SeasonStreakSummaryBuilder.php
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

A "Profile" eyebrow sits over a "{firstName}, / *your story.*" headline with the athlete's avatar circle beside it. Below, [ProfileHero](resources/js/components/profile/ProfileHero.tsx) pairs a 64px leaf-ringed [FaceIcon](resources/js/components/temari/FaceIcon.tsx) with **"★ What Temari says about you"** — the AI profile voice (`profileVoice`), rendered through [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx) in the `.narration` prose register. An "Est. {date}" line carries the first-run date at every width; a "With Temari since" block is revealed to its right only above 900px (the prototype's one visibility-toggled element). Strava status (`identity.strava_connected`) shows as a "Reconnect" action when revoked.

The panel is **card-toned with a horizon halo**, not a sky-gradient panel — `PS10` matched the prototype's own `bg-card` hero, as `PS8` did on activity detail.

This is the merged profile voice: it reads who the runner is from their 12-week mood mix and backs that reading with their lifetime numbers, in one billed call ([ProfileVoiceNarrator](app/Services/AI/Narrators/ProfileVoiceNarrator.php) carries `get_persona_mix` alongside `get_lifetime_stats`, `get_training_paces` and `get_progression_signal`). Server side, `ProfileController::resolveProfileVoice` looks up the `ProfileVoice` analysis keyed by **ISO week** (`isoFormat('GGGG-[W]WW')`) and returns `Analysis::toPayload`. The numbers on the page are live; the prose is refreshed once a week by `ai:weekly-profile` (`invalidate: false`, so the week key is the refresh) or on demand via "Reread". See [[recaps]] and [[ai-pipeline]].

**The evidence it may quote is bounded by the schema, not by the prompt's prose.** The prompt has asked for "one, at most two" numbers since the merge, and live output stacked four of them beside the mood percentages. The fix is [TrendReadNarrator](app/Services/AI/Narrators/TrendReadNarrator.php#L114)'s `reading` move applied here: the response schema carries two required `evidence_*` slots ahead of the paragraph ([ProfileVoiceNarrator](app/Services/AI/Narrators/ProfileVoiceNarrator.php#L178)), the model fills them with the already-formatted figures before it writes a word, and the paragraph may quote nothing but those two — so a third figure has no field to arrive in. The slots are never rendered; only required. The same output printed `1607 seconds` where [ProgressionCard](resources/js/components/profile/ProgressionCard.tsx#L90) prints `26:47` for the same delta, so [ProgressionSignalTool](app/Services/AI/Agent/Tools/ProgressionSignalTool.php#L81) now returns `delta_formatted` beside the raw `delta_sec` and its description makes the formatted one the only quotable form.

## Stat row

A horizontally scrolling row inside the hero: **Total km**, **Total runs**, **Longest run**, plus **VDOT** and **Threshold** when the athlete has a VDOT-eligible PR. The controller delegates to [LifetimeStats](app/Services/Run/LifetimeStats.php), the same service `/calendar` uses: one aggregate query over `ActivityDetail` (`SUM(distance)`, `MAX(distance)`, `MIN(start_date_local)`) plus `user->activities()->count()` for the run count, converted to km and cached per user for 5 minutes. `/profile` maps its `longest_km` onto the `longest_run_km` prop; the page renders **Total km** at 1dp and **Longest run** at 2dp, matching the precision the service rounds to.

Sharing `/calendar`'s cache means the totals can trail a just-ingested run by up to the TTL, the same window `/calendar` has always had.

## Fitness — VDOT, threshold pace & training paces

When the runner has a VDOT-eligible PR, the hero stat row grows two more tiles (**VDOT**, **Threshold**) and [PaceTargetsCard](resources/js/components/profile/PaceTargetsCard.tsx) renders below the season card: a leaf→horizon rail with four markers — **Easy**, **Marathon**, **Tempo**, **Interval** — each a pace-per-km via `formatPace`. Unlike the prototype's hardcoded marker offsets, each marker's position is its own pace linearly placed between the slowest and the fastest of the four, so two targets that sit close together read as close together. `ProfileController::fitness` builds the `fitness` prop from [VdotEstimator](app/Services/Run/Metrics/VdotEstimator.php)`::estimate`, [EstimateThresholdAction](app/Actions/Run/Metrics/EstimateThresholdAction.php)`::__invoke` and [TrainingPaceCalculator](app/Services/Run/Metrics/TrainingPaceCalculator.php)`::fromVdotResult`; `fitness` is `null` (and the extra tiles don't render) when the user has no VDOT-eligible PR yet.

These are the same estimators [ProfileVoiceNarrator](app/Services/AI/Narrators/ProfileVoiceNarrator.php) calls via [TrainingPacesTool](app/Services/AI/Agent/Tools/TrainingPacesTool.php) to narrate pace targets in prose — the numbers reach the user both ways, tabulated here and spoken in the hero voice above.

### A PR only votes while it is current

`estimate` takes the **minimum** VDOT across categories on purpose, so no prescribed pace outruns a genuine PR. But [PersonalRecords](app/Services/Run/Metrics/PersonalRecords.php)`::updateIfFaster` only ever replaces a record with a *faster* one, so a record improves and never ages out on its own. Unbounded, the two rules compound: one hard effort from years ago wins the minimum forever and pins every prescribed pace to the fitness the athlete had then, which is self-reinforcing, since a well-periodized plan prescribes easy running and so rarely produces a new short-distance PR to displace it.

Only records set within `VdotEstimator::RECENT_MONTHS` (12) vote. Deliberately a cut on voting rights rather than a decay applied to the number: PR age is not evidence of detraining, and an athlete running steadily who simply has not raced still holds the fitness their old record proved. When *nothing* is recent the older estimate still stands — no null cliff for a returning athlete — but it comes back flagged `stale`, and [PaceTargetsCard](resources/js/components/profile/PaceTargetsCard.tsx) names the record and its date under the rail rather than presenting an old number as current.

### What counts as a threshold session

[EstimateThresholdAction](app/Actions/Run/Metrics/EstimateThresholdAction.php) reports the median best-sustained pace across the athlete's quality sessions in the trailing 60 days. Two things decide which sessions those are, and both were wrong until measured against a real athlete's 60 days.

**The filter reads Z4+, not Z3+.** Z3 begins near 80% of max HR, which is where a runner with little genuine easy running spends most of an ordinary day. A 30% *Z3+* bar admitted **30 of 38 runs**, including an easy 10 km at 7:31/km that logged 84.3% Z3+. The same 30% bar on *Z4+* admits **8**, and the split is clean: everything accepted ran 6:26/km or faster, everything rejected 6:43/km or slower. `StreamSummary::thresholdZoneShare()` is deliberately separate from `hardZoneShare()`, which has ten other callers and keeps its Z3-inclusive meaning.

**A 20-minute window counts.** The windows are tried longest-first, so a real hour of threshold work still wins where it exists. But quality sessions for this athlete ran **18 to 37 minutes**, and requiring 30 minutes discarded most of them — including a 28-minute 5 km time trial, the single strongest piece of evidence on record. The estimate was then drawn from slower, longer runs.

Together these produced **6:56/km at "high confidence" from 23 samples** for an athlete who had just run 5 km at 5:34/km. The figure is display-only (`ProfileController` is its one consumer, and no prescribed pace reads it), so it never distorted training — but it sits beside VDOT on the Profile, and a wrong number presented confidently is worse than no number.

### Endurance and quality read different evidence

A record is a **floor** on what the athlete could do on its own date, never a **ceiling** on what they can do now. One minimum taken across every distance *and* every date conflates the two, and on real data that broke the quality end outright: a hard half from four months ago outvoted a week-old 5 km and had the plan prescribing **interval reps slower than the athlete's own sub-maximal 5 km training pace**.

Raising the single anchor is not the fix either — it breaks the other end. Anchored on the recent short evidence, prescribed *easy* pace came out faster than the athlete's actual half-marathon **race** pace.

So `estimate()` returns two anchors, and [TrainingPaceCalculator::fromVdot()](app/Services/Run/Metrics/TrainingPaceCalculator.php) splits the four paces between them:

| pace | anchor | why |
|---|---|---|
| easy, marathon | `vdot` — minimum across every category in the 12-month window | never outrun a distance the athlete has actually proven |
| threshold, interval | `quality_vdot` — the same minimum over records inside `QUALITY_MONTHS` (3) and at or under `QUALITY_MAX_METERS` (10 km) | quality work should reflect what the athlete runs *now*, over durations quality work actually lasts |

The quality slice also needs **one record of at least `QUALITY_MIN_METERS` (3 km)** before it may establish an anchor at all. A shorter record is a few minutes of work and is frequently a closing surge inside an easy run, not an effort. It still *participates* in the minimum once sustained evidence sits beside it — the minimum keeps whichever evidence is most conservative, and on real data a 5:09 surge implied a lower VDOT than the athlete's genuine 5 km time trial, so dropping it would have made quality paces faster rather than safer. What it may not do is stand alone: without the rule, a half-marathoner racing at 6:59/km with one 3:30 kilometre on record is prescribed **tempo at 4:02/km and intervals at 3:47/km**.

`quality_vdot` can never fall below `vdot`: its slice is a subset of the endurance slice, so its minimum can only be higher. With no qualifying recent short record it simply equals `vdot`, which is the pre-split behaviour. `quality_source` is set only when the two genuinely diverge, and `PaceTargetsCard` then names both records rather than presenting one date as the source of all four numbers.

The window is measured from a caller-supplied date, defaulting to now. [TrainingBaseline](app/Services/Run/Plan/TrainingBaseline.php) threads its own `$asOf` through, so the as-of paths [ComplianceScorer](app/Services/Run/Plan/ComplianceScorer.php) and [SeasonSummaryBuilder](app/Services/Run/Plan/SeasonSummaryBuilder.php) already use judge an old week by the evidence that existed then, the same discipline as [[a-day-is-scored-when-it-is-run]].

## Time in zone · last 12 weeks

**P13.** [TimeInZoneBar](resources/js/components/profile/TimeInZoneBar.tsx) draws a segmented Z1-Z5 bar and a dot legend in the hero slot the behavioural persona mix used to occupy (`PersonaBar` and the `personaMix` prop were cut in `PP3`). The percentages come from [TimeInZoneSummary](app/Services/Run/Metrics/TimeInZoneSummary.php), which sums the per-run `time_in_zone_min` that [StreamAnalysis](app/Services/Run/Ingest/StreamAnalysis.php) already writes onto `activity_details.stream_summary` across the trailing 12 weeks and normalises them. Zone colours and labels are the shared `HR_ZONE_COLORS`/`HR_ZONE_LABELS` in [chartTokens](resources/js/lib/chartTokens.ts), the same pair the [[settings-hr-zones]] editor names its bands with.

The whole block is absent — bar, legend and label — when no run in the window recorded heart rate, rather than drawing an empty rail. `ProfileVoiceNarrator::personaMix()` and `PersonaMixTool` survive as narration context for the hero voice: `W2` verified both are live and kept them.

## Journey (progression)

When `progressionByCategory` is non-empty, [ProgressionCard](resources/js/components/profile/ProgressionCard.tsx) renders distance pills (5K / 10K / HM / FM), a "Then …, now …" best/worst readout, the gap as a quote, two stat chips, and [JourneyChart](resources/js/components/profile/JourneyChart.tsx) — an inline-SVG polyline with a fatter marker on the PR and a tappable tooltip per point. The series are built server-side by `ProfileController::buildProgressionByCategory` via `ProgressionSeriesBuilder`, over the four `PROGRESSION_CATEGORIES`.

The pills only offer distances the athlete actually has times at. The prototype draws all four because it has no data to be missing; offering a distance with nothing behind it would be a control that cannot work. `PS10` replaced the Chart.js `ProgressionChart` here — the prototype draws a compact journey line, not an axis-and-grid chart — which orphaned that component; `LineChart` itself survives, still lazy-loaded by Trends and Race.

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
[SeasonStreakSummaryBuilder](app/Services/Gamification/SeasonStreakSummaryBuilder.php)`::seasonPayload`.

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
