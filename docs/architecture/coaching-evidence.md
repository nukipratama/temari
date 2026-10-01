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
| Rest is reserved for reported concerning pain or illness | [Readiness.php:78](app/Services/Run/Metrics/Readiness.php#L78) | evidence-supported | [[#Meeusen2013]] |
| Form is unknown until 42 days of scored history follow the first scored day | [TrainingLoad.php:351](app/Services/Run/Metrics/TrainingLoad.php#L351) | heuristic | [[#AllenCoggan]], [[#Hellard2006]] |
| The form threshold rises continuously with CTL, so more load never reads fresher | [TrainingLoad.php:361](app/Services/Run/Metrics/TrainingLoad.php#L361) | heuristic | [[#AllenCoggan]], [[#Vermeire2022]], [[#Imbach2022]] |
| The personal-range guard compares unrounded load against the weeks before the current one, and only when the athlete is ahead of the prescription | [Readiness.php:62](app/Services/Run/Metrics/Readiness.php#L62), [TrainingLoad.php:143](app/Services/Run/Metrics/TrainingLoad.php#L143) | evidence-supported | [[#Frandsen2025]], [[#Nakaoka2021]], [[#Impellizzeri2020]] |
| The volume guard compares actual km-to-date with prescribed km-to-date, never with last week | [Readiness.php:61](app/Services/Run/Metrics/Readiness.php#L61), [BriefingContext.php:309](app/Services/Run/Story/BriefingContext.php#L309) | evidence-supported | [[#Frandsen2025]], [[#Buist2008]], [[#Soligard2016]] |
| Readiness and adaptation read load as unknown while the CTL window still awaits hydration, and the strain ratio is unknown during the form warm-up | [PlanAdapter.php:91](app/Services/Run/Plan/PlanAdapter.php#L91), [PlanAdapter.php:95](app/Services/Run/Plan/PlanAdapter.php#L95) | product choice | — |

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
