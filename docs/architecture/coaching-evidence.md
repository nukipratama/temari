---
title: Coaching evidence
description: The one curated reference list behind the app's coaching rules — each source keyed AuthorYear with grade and access, and a rule table mapping each rule to code and its label (evidence-supported, heuristic or product choice)
tags: [architecture, run]
status: living
reviewed: 2026-10-05
code_refs:
  - app/Services/Run/Metrics/TrainingPaceCalculator.php
  - app/Services/Run/Metrics/VdotEstimator.php
  - app/Services/Run/Plan/IntensityPrescriptionResolver.php
  - app/Services/Run/Metrics/Readiness.php
  - app/Services/Run/Metrics/TrainingLoad.php
  - app/Services/Run/Plan/PlanAdapter.php
  - app/Services/Run/Story/BriefingContext.php
  - app/Services/Run/Plan/SegmentGenerator.php
  - app/Services/Run/Plan/TrainingBaseline.php
  - app/Services/Run/Plan/SeasonService.php
  - app/Services/Run/Plan/PhaseSchedule.php
  - app/Services/Run/Plan/ComplianceScorer.php
  - app/Services/Run/Plan/PostRaceRecovery.php
  - app/Services/Run/Plan/PlanInputsGatherer.php
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Run/Plan/RaceAmbitionAssessor.php
  - app/Services/Run/Plan/RaceAmbition.php
  - app/Services/Run/Metrics/RiegelProjector.php
  - app/Services/Gamification/SeasonGamificationContext.php
  - app/Services/Run/Metrics/TrainingFormStatus.php
  - app/Services/Run/Plan/PlanRenderer.php
  - app/Services/Run/Plan/PlanRecalibrationService.php
  - app/Services/Run/Plan/CoachingReset.php
  - app/Services/Run/Story/Temari.php
  - resources/js/components/settings/HrZonesDisclosure.tsx
  - app/Services/Run/Metrics/FallOffExponent.php
  - app/Actions/Run/Metrics/ResolveHardEffortsAction.php
  - app/Console/Commands/Run/FitnessNotifyImprovementCommand.php
  - resources/js/lib/raceGoal.ts
---

# Coaching evidence

ADRs, feature notes and code docblocks cite a source here as `[[coaching-evidence#AuthorYear]]` rather than appealing to "coaching convention". This is a curated list: a source earns a heading only when a rule below relies on it. Each coaching-redesign layer appends its own sources and rule rows.

**Labels.** *Evidence-supported*: a peer-reviewed source backs the rule as applied. *Heuristic*: a defensible model or convention with no peer-reviewed validation for this use; books and non-peer-reviewed conventions (Daniels, Allen & Coggan, Edwards, Banister 1991) never back more than this. *Product choice*: a decision about what the app shows or asks, not a training claim.

**Grade** (strongest design in the source): MA meta-analysis · SR systematic review · RCT · CT controlled trial · COH cohort · XS cross-sectional · LAB laboratory or modelling · CON consensus statement · REV narrative review · NONE no peer review. **Access**: FT full text read · ABS abstract only · NR not read (provenance only).

## Rules

| App rule | Code | Label | Sources |
|---|---|---|---|
| Fitness is a VDOT fitted to race time with Daniels' sustainable-fraction curve; the same model turns a VDOT back into a race time | [VdotEstimator.php:705](app/Services/Run/Metrics/VdotEstimator.php#L705), [VdotEstimator.php:722](app/Services/Run/Metrics/VdotEstimator.php#L722), [VdotEstimator.php:729](app/Services/Run/Metrics/VdotEstimator.php#L729) | heuristic | [[#DanielsGilbert1979]], [[#OficialCasado2025]] |
| The marathon guide pace is the athlete's marathon race-equivalent pace | [TrainingPaceCalculator.php:17](app/Services/Run/Metrics/TrainingPaceCalculator.php#L17) | heuristic | [[#DanielsGilbert1979]] |
| Marathon pace is slower than threshold pace | [TrainingPaceCalculator.php:57](app/Services/Run/Metrics/TrainingPaceCalculator.php#L57) | evidence-supported | [[#SmythMunizPumares2020]], [[#Jones2021]] |
| The threshold guide pace is the pace the athlete could race for an hour | [TrainingPaceCalculator.php:19](app/Services/Run/Metrics/TrainingPaceCalculator.php#L19) | heuristic | [[#DanielsGilbert1979]], [[#Jones2010]] |
| The interval guide pace is the pace the athlete could race for about eleven minutes | [TrainingPaceCalculator.php:21](app/Services/Run/Metrics/TrainingPaceCalculator.php#L21) | heuristic | [[#DanielsGilbert1979]] |
| The easy guide pace is the midpoint of the VDOT calculator's E band | [TrainingPaceCalculator.php:86](app/Services/Run/Metrics/TrainingPaceCalculator.php#L86) | heuristic | [[#DanielsGilbert1979]] |
| A continuous threshold block (Peak, 35 minutes) never runs longer than the athlete's own model says they could race at its pace | [IntensityPrescriptionResolver.php:15](app/Services/Run/Plan/IntensityPrescriptionResolver.php#L15) | evidence-supported | [[#Jones2010]], [[#Jamnick2020]] |
| A CTL/ATL form label never forces rest or a deload; "fatigued" or "overreaching" only supports a mild reported concern toward a ModerateOk advisory | [Readiness.php:66](app/Services/Run/Metrics/Readiness.php#L66) | evidence-supported | [[#Meeusen2013]], [[#Saw2016]] |
| Rest is reserved for reported concerning pain or illness | [Readiness.php:76](app/Services/Run/Metrics/Readiness.php#L76) | evidence-supported | [[#Meeusen2013]] |
| Form is unknown until 42 days of scored history follow the first scored day | [TrainingLoad.php:351](app/Services/Run/Metrics/TrainingLoad.php#L351) | heuristic | [[#AllenCoggan]], [[#Hellard2006]] |
| The form threshold rises continuously with CTL, so more load never reads fresher | [TrainingLoad.php:361](app/Services/Run/Metrics/TrainingLoad.php#L361) | heuristic | [[#AllenCoggan]], [[#Vermeire2022]], [[#Imbach2022]] |
| The personal-range guard compares unrounded load against the weeks before the current one, and only when the athlete is ahead of the prescription | [Readiness.php:62](app/Services/Run/Metrics/Readiness.php#L62), [TrainingLoad.php:143](app/Services/Run/Metrics/TrainingLoad.php#L143) | evidence-supported | [[#Frandsen2025]], [[#Nakaoka2021]], [[#Impellizzeri2020]] |
| The volume guard compares actual km-to-date with prescribed km-to-date, never with last week | [Readiness.php:61](app/Services/Run/Metrics/Readiness.php#L61), [BriefingContext.php:309](app/Services/Run/Story/BriefingContext.php#L309) | evidence-supported | [[#Frandsen2025]], [[#Buist2008]], [[#Soligard2016]] |
| Readiness and adaptation read load as unknown while the CTL window still awaits hydration | [PlanAdapter.php:70](app/Services/Run/Plan/PlanAdapter.php#L70) | product choice | — |
| Monotony and strain describe a week; neither deloads it nor caps readiness | [PlanAdapter.php:135](app/Services/Run/Plan/PlanAdapter.php#L135), [Readiness.php:139](app/Services/Run/Metrics/Readiness.php#L139) | evidence-supported | [[#Foster1998]], [[#JonesCM2017]] |
| A return after a gap is handled once, by the missed-week adaptation and the ramp, never as a separate strain deload | [PlanAdapter.php:135](app/Services/Run/Plan/PlanAdapter.php#L135) | evidence-supported | [[#Frandsen2025]], [[#Impellizzeri2020]] |
| No generated running session exceeds 110% of the longest run in the prior 30 days; with none, the cap is the cold-start long run | [SegmentGenerator.php:121](app/Services/Run/Plan/SegmentGenerator.php#L121), [TrainingBaseline.php:378](app/Services/Run/Plan/TrainingBaseline.php#L378) | evidence-supported | [[#Frandsen2025]] |
| A goal-less season's four-week cycle averages its frozen anchor, unless a long-run cap binds | [TrainingBaseline.php:511](app/Services/Run/Plan/TrainingBaseline.php#L511) | heuristic | [[#Doherty2020]], [[#Coyle1984]], [[#MujikaPadilla2000a]] |
| Following the prescription never lowers the anchor; only running well short of it re-anchors mid-season or at rollover | [SeasonService.php:240](app/Services/Run/Plan/SeasonService.php#L240) | heuristic | [[#Coyle1984]], [[#MujikaPadilla2000a]], [[#MujikaPadilla2000b]] |
| The Build ramp is a heuristic, not a safety rule | [PhaseSchedule.php:46](app/Services/Run/Plan/PhaseSchedule.php#L46) | heuristic | [[#Buist2008]] |
| Steady-segment decoupling describes a run; only easy days run above Z2 count a week as run too hard | [PlanAdapter.php:286](app/Services/Run/Plan/PlanAdapter.php#L286) | evidence-supported | [[#Smyth2022]], [[#CoyleGonzalezAlonso2001]], [[#Maunder2021]], [[#Racinais2015]] |
| Deleting a run re-grades its day from the surviving runs in either direction; an excused day keeps its verdict | [ComplianceScorer.php:321](app/Services/Run/Plan/ComplianceScorer.php#L321) | product choice | — |
| After a marathon-class race (30 km or more run), 14 days carry no quality and the first full week after race day runs at the deload multiplier | [PostRaceRecovery.php:28](app/Services/Run/Plan/PostRaceRecovery.php#L28), [PlanInputsGatherer.php:130](app/Services/Run/Plan/PlanInputsGatherer.php#L130), [Periodizer.php:475](app/Services/Run/Plan/Periodizer.php#L475), [Periodizer.php:775](app/Services/Run/Plan/Periodizer.php#L775) | evidence-supported | [[#Sherman1984]], [[#Warhol1985]], [[#MartinezNavarro2021]] |
| After a race of over 15 km, 7 days carry no quality; after 15 km or less, 3 days; the 30 km and 15 km class thresholds are conventions (the research found no half-marathon or shorter recovery timelines) | [PostRaceRecovery.php:17](app/Services/Run/Plan/PostRaceRecovery.php#L17), [PostRaceRecovery.php:19](app/Services/Run/Plan/PostRaceRecovery.php#L19), [PostRaceRecovery.php:28](app/Services/Run/Plan/PostRaceRecovery.php#L28) | heuristic | — |
| The supported VDOT is read at the race distance (the goal race, or 10K) from whole-run hard efforts of the last 16 weeks, newest per ±10% band; nothing in 16 weeks falls back to the newest older effort, labelled stale | [VdotEstimator.php:75](app/Services/Run/Metrics/VdotEstimator.php#L75), [VdotEstimator.php:79](app/Services/Run/Metrics/VdotEstimator.php#L79), [VdotEstimator.php:301](app/Services/Run/Metrics/VdotEstimator.php#L301) | evidence-supported (window), heuristic (fallback) | [[#SmythMunizPumares2020]], [[#EmigPeltonen2020]], [[#Coyle1984]] |
| An unconfirmed hard effort is a distance record covering essentially the whole run; embedded segments, the Strava workout tag and heart rate never qualify a run | [ResolveHardEffortsAction.php:47](app/Actions/Run/Metrics/ResolveHardEffortsAction.php#L47) | heuristic | [[#MolinaGarcia2022]] |
| Efforts bracketing the race distance are log-interpolated, otherwise the closest sets it; each projection is the slower of the VDOT equivalence and a power law with the athlete's fall-off | [VdotEstimator.php:350](app/Services/Run/Metrics/VdotEstimator.php#L350), [VdotEstimator.php:380](app/Services/Run/Metrics/VdotEstimator.php#L380) | evidence-supported | [[#EmigPeltonen2020]], [[#BlytheKiraly2016]], [[#VickersVertosick2016]], [[#Riegel1981]] |
| The personal fall-off k is fitted within an 8-week cluster spanning at least 1.5× in distance, clamped to [1.06, 1.15], defaulting to 1.08 up to 10K, 1.10 to the half and 1.15 beyond | [FallOffExponent.php:49](app/Services/Run/Metrics/FallOffExponent.php#L49), [FallOffExponent.php:34](app/Services/Run/Metrics/FallOffExponent.php#L34) | heuristic | [[#BlytheKiraly2016]], [[#VickersVertosick2016]], [[#Riegel1981]] |
| A recent training run of at least the race distance floors the supported time; it never sets one alone, and heart rate never becomes a time | [VdotEstimator.php:395](app/Services/Run/Metrics/VdotEstimator.php#L395) | evidence-supported | [[#SmythMunizPumares2020]], [[#Hunter2023]], [[#MolinaGarcia2022]] |
| A rise resting on unconfirmed records lifts the supported VDOT by at most 1.0 a week from the anchor's capture; confirmed rises and drops apply at once | [VdotEstimator.php:228](app/Services/Run/Metrics/VdotEstimator.php#L228) | heuristic | — |
| An improvement of at least 0.5 VDOT is noted at most once a week; the Race page and Trends name the effort behind the supported time, and never ask the athlete to vouch for a run | [FitnessNotifyImprovementCommand.php:22](app/Console/Commands/Run/FitnessNotifyImprovementCommand.php#L22), [raceGoal.ts:206](resources/js/lib/raceGoal.ts#L206) | product choice | — |
| A target backed by evidence covering under half the race distance is `low_evidence`, and the prescribed race time is the slower of target and supported | [RaceAmbitionAssessor.php:44](app/Services/Run/Plan/RaceAmbitionAssessor.php#L44), [RaceAmbition.php:22](app/Services/Run/Plan/RaceAmbition.php#L22) | evidence-supported | [[#VickersVertosick2016]], [[#Keogh2019]], [[#OficialCasado2025]], [[#BlytheKiraly2016]], [[#Riegel1981]] |
| The 3% and 6% ambition bands against supported race time | [RaceAmbitionAssessor.php:17](app/Services/Run/Plan/RaceAmbitionAssessor.php#L17), [RaceAmbitionAssessor.php:19](app/Services/Run/Plan/RaceAmbitionAssessor.php#L19) | heuristic | — |
| A Riegel projection slower than the goal adds no quality session; the projection is display only, with its fitted exponent floored at 1.0 and efforts under 3.5 min excluded | [PlanAdapter.php:148](app/Services/Run/Plan/PlanAdapter.php#L148), [RiegelProjector.php:52](app/Services/Run/Metrics/RiegelProjector.php#L52), [RiegelProjector.php:57](app/Services/Run/Metrics/RiegelProjector.php#L57) | evidence-supported | [[#Riegel1981]], [[#BlytheKiraly2016]], [[#VickersVertosick2016]] |
| A goal-less season's goal counts weeks that reached 85% of the planned km, not CTL growth | [SeasonGamificationContext.php:176](app/Services/Gamification/SeasonGamificationContext.php#L176), [SeasonService.php:473](app/Services/Run/Plan/SeasonService.php#L473) | product choice | [[#Vermeire2022]], [[#Doherty2020]] |
| Load numbers are presented as running load: long-term load, short-term load and a three-state load balance (fresh, steady, heavy); no copy reads them as fitness, readiness, overreaching, injury or soreness | [TrainingFormStatus.php:17](app/Services/Run/Metrics/TrainingFormStatus.php#L17) | evidence-supported | [[#Meeusen2013]], [[#Vermeire2022]], [[#Foster1998]], [[#JonesCM2017]], [[#Smyth2022]], [[#Mountjoy2023]] |
| Heart-rate zones without a measured max or synced bands are labelled estimated, and heart-rate intent verdicts on them are marked a rough read | [HrZonesDisclosure.tsx:95](resources/js/components/settings/HrZonesDisclosure.tsx#L95), [ComplianceScorer.php:57](app/Services/Run/Plan/ComplianceScorer.php#L57) | evidence-supported | [[#Tanaka2001]], [[#Nes2013]], [[#RobergsLandwehr2002]] |
| No age is collected to predict a max heart rate; the default 180 and the observed-peak raise stand | [runner.php:8](config/runner.php#L8) | product choice | — |
| The Race page and Trends show the target beside the supported time, and "on track for" only in the on-track band with the supported time not behind the target | [raceGoal.ts:169](resources/js/lib/raceGoal.ts#L169), [TrendsController.php:68](app/Http/Controllers/TrendsController.php#L68) | product choice | [[#VickersVertosick2016]] |
| The advised session (a recorded ease, or today's advisory) leads the day on every surface; a pinned or Race day keeps its prescription and carries the advice as a note | [PlanRenderer.php:241](app/Services/Run/Plan/PlanRenderer.php#L241) | product choice | — |
| A run's fallback mood follows the same effort scale as its colour | [Temari.php:109](app/Services/Run/Story/Temari.php#L109) | product choice | — |
| Ordinary recalibration recomputes metrics and the future plan but keeps past prescriptions and shown-advice grades | [PlanRecalibrationService.php:47](app/Services/Run/Plan/PlanRecalibrationService.php#L47) | product choice (#1511) | — |
| One pre-launch reset rebuilds derived history once under the current policy | [CoachingReset.php:64](app/Services/Run/Plan/CoachingReset.php#L64) | product choice (#1541) | — |

## Sources

### DanielsGilbert1979
Daniels J, Gilbert J. *Oxygen Power: Performance Tables for Distance Runners.* Self-published, 1979. Book, no DOI. The VDOT model and its training paces; the live reference is the [VDOT calculator](https://vdoto2.com/calculator) and its [training definitions](https://vdoto2.com/learn-more/training-definitions). Grade NONE · access NR.

### OficialCasado2025
Oficial-Casado F, Priego-Quesada JI, Pérez-Soriano P. Performance prediction equation for the Valencia Marathon based on time and pacing in the half marathon. *Front Physiol* 2025;16:1718298. https://doi.org/10.3389/fphys.2025.1718298. The best independent test of VDOT found: marathon-from-half accuracy similar to a fitted regression (MAE ~5.9%), better for sub-3-hour runners, worse for slower ones. Grade COH · access ABS.

### SmythMunizPumares2020
Smyth B, Muniz-Pumares D. Calculation of critical speed from raw training data in recreational marathon runners. *Med Sci Sports Exerc* 2020;52(12):2637–2645. https://doi.org/10.1249/MSS.0000000000002412. Recreational marathoners raced at ~85% of critical speed on average. Critical speed came from each runner's best training efforts of 400 m to 5 km in the 16 weeks before the race, weighted equally, and predicted marathon time within 7.7%. Grade COH · access ABS.

### Jones2021
Jones AM, Kirby BS, Clark IE, et al. Physiological demands of running at 2-hour marathon race pace. *J Appl Physiol* 2021;130(2):369–379. https://doi.org/10.1152/japplphysiol.00647.2020. Even elite marathon pace sits just under critical speed. Grade LAB · access ABS.

### Jones2010
Jones AM, Vanhatalo A, Burnley M, Morton RH, Poole DC. Critical power: implications for determination of VO2max and exercise tolerance. *Med Sci Sports Exerc* 2010;42(10):1876–1890. https://doi.org/10.1249/MSS.0b013e3181d9cf7f. Critical speed bounds the heavy and severe domains; an effort sustainable for only ~20 minutes lies above it. Grade REV · access ABS.

### Jamnick2020
Jamnick NA, Pettitt RW, Granata C, Pyne DB, Bishop DJ. An examination and critique of current methods to determine exercise intensity. *Sports Med* 2020;50:1729–1756. https://doi.org/10.1007/s40279-020-01322-8. LT2 and critical speed mark the threshold; fixed %VO2max anchors place different people in different domains. Grade REV · access ABS.

### AllenCoggan
Allen H, Coggan A. *Training and Racing with a Power Meter.* VeloPress, 2nd ed. 2010. Book, no DOI. Origin of the CTL/ATL/TSB conventions. Grade NONE · access NR.

### Meeusen2013
Meeusen R, Duclos M, Foster C, et al. Prevention, diagnosis, and treatment of the overtraining syndrome: joint consensus statement of the ECSS and ACSM. *Med Sci Sports Exerc* 2013;45(1):186–205. https://doi.org/10.1249/MSS.0b013e318279a10a. Overreaching is a performance-based diagnosis; no load number is diagnostic. Grade CON · access FT.

### Saw2016
Saw AE, Main LC, Gastin PB. Monitoring the athlete training response: subjective self-reported measures trump commonly used objective measures: a systematic review. *Br J Sports Med* 2016;50(5):281–291. https://doi.org/10.1136/bjsports-2015-094758. Self-reported wellbeing tracks load better than objective markers. Grade SR · access FT.

### Hellard2006
Hellard P, Avalos M, Lacoste L, et al. Assessing the limitations of the Banister model in monitoring training. *J Sports Sci* 2006;24(5):509–520. https://doi.org/10.1080/02640410500244697. Fitness-fatigue parameters are unstable across athletes. Grade LAB · access FT.

### Vermeire2022
Vermeire K, Ghijs M, Bourgois JG, Boone J. The fitness-fatigue model: what's in the numbers? *Int J Sports Physiol Perform* 2022;17(5):810–813. https://doi.org/10.1123/ijspp.2021-0494. Grade REV · access ABS.

### Imbach2022
Imbach F, Sutton-Charani N, Montmain J, Candau R, Perrey S. The use of fitness-fatigue models for sport performance modelling: conceptual issues and contributions from machine-learning. *Sports Med Open* 2022;8:29. https://doi.org/10.1186/s40798-022-00426-x. Grade REV · access ABS.

### Frandsen2025
Schuster Brandt Frandsen J, Hulme A, Parner ET, et al. How much running is too much? Identifying high-risk running sessions in a 5200-person cohort study. *Br J Sports Med* 2025;59:1203–1210. https://doi.org/10.1136/bjsports-2024-109380. A single-session spike over the longest recent run carried risk; the week-to-week ratio showed no association. Grade COH · access FT.

### Nakaoka2021
Nakaoka G, Barboza SD, Verhagen E, van Mechelen W, Hespanhol L. The association between the acute:chronic workload ratio and running-related injuries in Dutch runners: a prospective cohort study. *Sports Med* 2021;51(11):2437–2447. https://doi.org/10.1007/s40279-021-01483-0. Grade COH · access ABS.

### Impellizzeri2020
Impellizzeri FM, Tenan MS, Kempton T, Novak A, Coutts AJ. Acute:chronic workload ratio: conceptual issues and fundamental pitfalls. *Int J Sports Physiol Perform* 2020;15:907–913. https://doi.org/10.1123/ijspp.2019-0864. Grade REV · access ABS.

### Buist2008
Buist I, Bredeweg SW, van Mechelen W, et al. No effect of a graded training program on the number of running-related injuries in novice runners: a randomized controlled trial. *Am J Sports Med* 2008;36(1):33–39. https://doi.org/10.1177/0363546507307505. The weekly 10% rule did not protect novices. Grade RCT · access ABS.

### Soligard2016
Soligard T, Schwellnus M, Alonso JM, et al. How much is too much? (Part 1) IOC consensus statement on load in sport and risk of injury. *Br J Sports Med* 2016;50(17):1030–1041. https://doi.org/10.1136/bjsports-2016-096581. Flags rapid load change without giving thresholds. Grade CON · access ABS.

### Foster1998
Foster C. Monitoring training in athletes with reference to overtraining syndrome. *Med Sci Sports Exerc* 1998;30(7):1164–1168. https://doi.org/10.1097/00005768-199807000-00023. Monotony and strain came from 25 athletes with individual illness thresholds; the abstract states no universal monotony cutoff. Grade COH · access ABS.

### JonesCM2017
Jones CM, Griffiths PC, Mellalieu SD. Training load and fatigue marker associations with injury and illness: a systematic review of longitudinal studies. *Sports Med* 2017;47(5):943–974. https://doi.org/10.1007/s40279-016-0619-5. Monotony and strain associations with injury and illness were mixed across studies. Grade SR · access FT.

### Coyle1984
Coyle EF, Martin WH, Sinacore DR, et al. Time course of loss of adaptations after stopping prolonged intense endurance training. *J Appl Physiol* 1984;57(6):1857–1864. https://doi.org/10.1152/jappl.1984.57.6.1857. VO2max fell 7% in 21 days and settled 16% below trained by 56 days. Grade LAB · access ABS.

### MujikaPadilla2000a
Mujika I, Padilla S. Detraining: loss of training-induced physiological and performance adaptations. Part I: short term insufficient training stimulus. *Sports Med* 2000;30(2):79–87. https://doi.org/10.2165/00007256-200030020-00002. Grade REV · access ABS.

### MujikaPadilla2000b
Mujika I, Padilla S. Detraining: loss of training-induced physiological and performance adaptations. Part II: long term insufficient training stimulus. *Sports Med* 2000;30(3):145–154. https://doi.org/10.2165/00007256-200030030-00001. Grade REV · access ABS.

### Doherty2020
Doherty C, Keogh A, Davenport J, et al. An evaluation of the training determinants of marathon performance: a meta-analysis with meta-regression. *J Sci Med Sport* 2020;23(2):182–188. https://doi.org/10.1016/j.jsams.2019.09.013. Weekly distance, frequency and longest run were associated with faster times, as cohort-level associations confounded by ability. Grade MA · access ABS.

### Smyth2022
Smyth B, Maunder E, Meyler S, Hunter B, Muniz-Pumares D. Decoupling of internal and external workload during a marathon: an analysis of durability in 82,303 recreational runners. *Sports Med* 2022;52(9):2283–2295. https://doi.org/10.1007/s40279-022-01680-5. Decoupling appeared around 25 km; low-decoupling runners raced at a higher fraction of critical speed and finished faster. Grade COH · access ABS.

### CoyleGonzalezAlonso2001
Coyle EF, González-Alonso J. Cardiovascular drift during prolonged exercise: new perspectives. *Exerc Sport Sci Rev* 2001;29(2):88–92. https://doi.org/10.1097/00003677-200104000-00009. Stroke volume falls and heart rate rises after 10–20 minutes, linked to rising body temperature and dehydration. Grade REV · access ABS.

### Maunder2021
Maunder E, Seiler S, Mildenhall MJ, Kilding AE, Plews DJ. The importance of "durability" in the physiological profiling of endurance athletes. *Sports Med* 2021;51(8):1619–1628. https://doi.org/10.1007/s40279-021-01459-0. Durability, how physiological profiles degrade over prolonged exercise, is a distinct performance trait. Grade REV · access ABS.

### Racinais2015
Racinais S, Alonso JM, Coutts AJ, et al. Consensus recommendations on training and competing in the heat. *Sports Med* 2015;45(7):925–938. https://doi.org/10.1007/s40279-015-0343-6. Heat raises heart rate at a given pace; training in heat should be regulated by heart rate or effort, not pace. Grade CON · access FT.

### Sherman1984
Sherman WM, Armstrong LE, Murray TM, et al. Effect of a 42.2-km footrace and subsequent rest or exercise on muscular strength and work capacity. *J Appl Physiol* 1984;57(6):1668–1673. https://doi.org/10.1152/jappl.1984.57.6.1668. Knee-extensor strength fell after a marathon; rest recovered it better than 20–45 min/day of easy running over 7 days, and it was still below baseline on day 7 in both groups (n = 10). Grade RCT · access ABS.

### MartinezNavarro2021
Martínez-Navarro I, Montoya-Vieco A, Hernando C, et al. The week after running a marathon: effects of running vs elliptical training vs resting on neuromuscular performance and muscle damage recovery. *Eur J Sport Sci* 2021;21(12):1668–1674. https://doi.org/10.1080/17461391.2020.1857441. Returning to running at 48 h did not change CK or LDH recovery up to 8 days and improved squat jump at 96 h (n = 64). Grade CT · access ABS.

### Warhol1985
Warhol MJ, Siegel AJ, Evans WJ, Silverman LM. Skeletal muscle injury and repair in marathon runners after competition. *Am J Pathol* 1985;118(2):331–339. https://pubmed.ncbi.nlm.nih.gov/3970143/. Biopsies showed fibre damage at 1–3 days, repair at 3–4 weeks and regeneration markers at 8–12 weeks. Grade LAB · access ABS.

### VickersVertosick2016
Vickers AJ, Vertosick EA. An empirical study of race times in recreational endurance runners. *BMC Sports Sci Med Rehabil* 2016;8:26. https://doi.org/10.1186/s13102-016-0052-y. In 2,303 recreational runners Riegel was calibrated to the half marathon but much too fast for the marathon, and its error was largest in less-trained runners. Models adding weekly volume or a second race did better. Grade COH · access ABS.

### Keogh2019
Keogh A, Smyth B, Caulfield B, et al. Prediction equations for marathon performance: a systematic review. *Int J Sports Physiol Perform* 2019;14(9):1159–1169. https://doi.org/10.1123/ijspp.2019-0360. 114 equations in 36 studies, R² from 0.10 to 0.99; most omit course, sex and weather, so no single equation is recommended. Grade SR · access ABS.

### BlytheKiraly2016
Blythe DAJ, Király FJ. Prediction and quantification of individual athletic performance of runners. *PLoS One* 2016;11:e0157257. https://doi.org/10.1371/journal.pone.0157257. Individual runners have distance-dependent exponents; a 3-parameter individual model cut prediction error by about 30% against existing methods (164,746 runners). Grade COH · access ABS.

### Riegel1981
Riegel PS. Athletic records and human endurance. *Am Sci* 1981;69(3):285–290. https://pubmed.ncbi.nlm.nih.gov/7235349/. T2 = T1·(D2/D1)^1.06, fitted to world records from about 3.5 to 230 min; a fatigue factor, not physiology. Grade NONE · access NR.

### Mountjoy2023
Mountjoy M, Ackerman KE, Bailey DM, et al. 2023 International Olympic Committee's (IOC) consensus statement on Relative Energy Deficiency in Sport (REDs). *Br J Sports Med* 2023;57(17):1073–1097. https://doi.org/10.1136/bjsports-2023-106994. Low energy availability has health and performance effects that no training-load number identifies. Grade CON · access ABS.

### Tanaka2001
Tanaka H, Monahan KD, Seals DR. Age-predicted maximal heart rate revisited. *J Am Coll Cardiol* 2001;37:153–156. https://doi.org/10.1016/s0735-1097(00)01054-8. A meta-analysis of 351 studies (18,712 subjects) giving HRmax = 208 − 0.7 × age, independent of sex and activity level; any age formula is an estimate, not a measurement. Grade MA · access ABS.

### Nes2013
Nes BM, Janszky I, Wisløff U, Støylen A, Karlsen T. Age-predicted maximal heart rate in healthy subjects: the HUNT Fitness Study. *Scand J Med Sci Sports* 2013;23(6):697–704. https://doi.org/10.1111/j.1600-0838.2012.01445.x. In a large healthy cohort HRmax = 211 − 0.64 × age fitted better than 220 − age, with individual error still around ±10 bpm. Grade COH · access ABS.

### RobergsLandwehr2002
Robergs RA, Landwehr R. The surprising history of the "HRmax = 220 − age" equation. *J Exerc Physiol Online* 2002;5(2):1–10. https://www.asep.org/asep/asep/Robergs2.pdf (no DOI). The 220 − age equation was fitted by eye to about eleven heterogeneous sources and carries a 7–11 bpm prediction error. Grade REV · access ABS.

### Foster2001
Foster C, Florhaug JA, Franklin J, Gottschall L, Hrovatin LA, Parker S, Doleshal P, Dodge C. A new approach to monitoring exercise training. *J Strength Cond Res* 2001;15(1):109–115. https://pubmed.ncbi.nlm.nih.gov/11708692/. Session RPE (CR-10 × minutes) related consistently to a heart-rate zone method in cycling and basketball, so it works across modes. Grade CT · access ABS. No rule reads it yet: it is the basis for #1577, which decides what coaching does with the effort score ([[an-effort-score-is-collected-on-every-run]]).

### Haddad2017
Haddad M, Stylianides G, Djaoui L, Dellal A, Chamari K. Session-RPE method for training load monitoring: validity, ecological usefulness, and influencing factors. *Front Neurosci* 2017;11:612. https://doi.org/10.3389/fnins.2017.00612. Across 36 studies session RPE was valid, reliable and internally consistent in many sports, ages and both sexes. Grade SR · access ABS. Basis for #1577.

### Wallace2014
Wallace LK, Slattery KM, Coutts AJ. A comparison of methods for quantifying training load: relationships between modelled and actual training responses. *Eur J Appl Physiol* 2014;114(1):11–20. https://doi.org/10.1007/s00421-013-2745-1. In seven runners over 15 weeks, session RPE, TRIMP and rTSS loads each fitted the measured performance response moderately to strongly. Grade COH · access ABS. Basis for #1577.

### EmigPeltonen2020
Emig T, Peltonen J. Human running performance from real-world big data. *Nat Commun* 2020. https://pmc.ncbi.nlm.nih.gov/articles/PMC7538888/ Modelled each runner from all running activities in the 180 days before a marathon; across seasons with three or more races the mean error between model and race time was 2.0%. Grade COH · access FT.

### Hunter2023
Hunter B, Ledger A, Muniz-Pumares D. Remote determination of critical speed and critical power in recreational runners. *Int J Sports Physiol Perform* 2023;18(12):1449–1456. https://doi.org/10.1123/ijspp.2023-0276. Critical speed from habitual training data did not differ from time trials or a 3-minute all-out test. Grade XS · access ABS.

### MolinaGarcia2022
Molina-Garcia P, Notbohm HL, Schumann M, et al. Validity of estimating the maximal oxygen consumption by consumer wearables: a systematic review with meta-analysis and expert statement of the INTERLIVE network. *Sports Med* 2022;52(7):1577–1597. https://pubmed.ncbi.nlm.nih.gov/35072942/ Wearable VO2max estimates from heart rate and pace had small group bias but wide individual limits of agreement. Grade MA · access ABS.
