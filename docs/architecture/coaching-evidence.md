---
title: Coaching evidence
description: The one curated reference list behind the app's coaching rules — each source keyed AuthorYear with grade and access, and a rule table mapping each rule to code and its label (evidence-supported, heuristic or product choice)
tags: [architecture, run]
status: living
reviewed: 2026-10-02
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
---

# Coaching evidence

ADRs, feature notes and code docblocks cite a source here as `[[coaching-evidence#AuthorYear]]` rather than appealing to "coaching convention". This is a curated list: a source earns a heading only when a rule below relies on it. Each coaching-redesign layer appends its own sources and rule rows.

**Labels.** *Evidence-supported*: a peer-reviewed source backs the rule as applied. *Heuristic*: a defensible model or convention with no peer-reviewed validation for this use; books and non-peer-reviewed conventions (Daniels, Allen & Coggan, Edwards, Banister 1991) never back more than this. *Product choice*: a decision about what the app shows or asks, not a training claim.

**Grade** (strongest design in the source): MA meta-analysis · SR systematic review · RCT · CT controlled trial · COH cohort · XS cross-sectional · LAB laboratory or modelling · CON consensus statement · REV narrative review · NONE no peer review. **Access**: FT full text read · ABS abstract only · NR not read (provenance only).

## Rules

| App rule | Code | Label | Sources |
|---|---|---|---|
| Fitness is a VDOT fitted to race time with Daniels' sustainable-fraction curve; the same model turns a VDOT back into a race time | [VdotEstimator.php:481](app/Services/Run/Metrics/VdotEstimator.php#L481), [VdotEstimator.php:498](app/Services/Run/Metrics/VdotEstimator.php#L498), [VdotEstimator.php:505](app/Services/Run/Metrics/VdotEstimator.php#L505) | heuristic | [[#DanielsGilbert1979]], [[#OficialCasado2025]] |
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
| No generated running session exceeds 110% of the longest run in the prior 30 days; with none, the cap is the cold-start long run | [SegmentGenerator.php:119](app/Services/Run/Plan/SegmentGenerator.php#L119), [TrainingBaseline.php:378](app/Services/Run/Plan/TrainingBaseline.php#L378) | evidence-supported | [[#Frandsen2025]] |
| A goal-less season's four-week cycle averages its frozen anchor, unless a long-run cap binds | [TrainingBaseline.php:511](app/Services/Run/Plan/TrainingBaseline.php#L511) | heuristic | [[#Doherty2020]], [[#Coyle1984]], [[#MujikaPadilla2000a]] |
| Following the prescription never lowers the anchor; only running well short of it re-anchors mid-season or at rollover | [SeasonService.php:240](app/Services/Run/Plan/SeasonService.php#L240) | heuristic | [[#Coyle1984]], [[#MujikaPadilla2000a]], [[#MujikaPadilla2000b]] |
| The Build ramp is a heuristic, not a safety rule | [PhaseSchedule.php:46](app/Services/Run/Plan/PhaseSchedule.php#L46) | heuristic | [[#Buist2008]] |
| Steady-segment decoupling describes a run; only easy days run above Z2 count a week as run too hard | [PlanAdapter.php:286](app/Services/Run/Plan/PlanAdapter.php#L286) | evidence-supported | [[#Smyth2022]], [[#CoyleGonzalezAlonso2001]], [[#Maunder2021]], [[#Racinais2015]] |
| Deleting a run re-grades its day from the surviving runs in either direction; an excused day keeps its verdict | [ComplianceScorer.php:321](app/Services/Run/Plan/ComplianceScorer.php#L321) | product choice | — |

## Sources

### DanielsGilbert1979
Daniels J, Gilbert J. *Oxygen Power: Performance Tables for Distance Runners.* Self-published, 1979. Book, no DOI. The VDOT model and its training paces; the live reference is the [VDOT calculator](https://vdoto2.com/calculator) and its [training definitions](https://vdoto2.com/learn-more/training-definitions). Grade NONE · access NR.

### OficialCasado2025
Oficial-Casado F, Priego-Quesada JI, Pérez-Soriano P. Performance prediction equation for the Valencia Marathon based on time and pacing in the half marathon. *Front Physiol* 2025;16:1718298. https://doi.org/10.3389/fphys.2025.1718298. The best independent test of VDOT found: marathon-from-half accuracy similar to a fitted regression (MAE ~5.9%), better for sub-3-hour runners, worse for slower ones. Grade COH · access ABS.

### SmythMunizPumares2020
Smyth B, Muniz-Pumares D. Calculation of critical speed from raw training data in recreational marathon runners. *Med Sci Sports Exerc* 2020;52(12):2637–2645. https://doi.org/10.1249/MSS.0000000000002412. Recreational marathoners raced at ~85% of critical speed on average. Grade COH · access ABS.

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
