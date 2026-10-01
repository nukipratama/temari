---
title: Coaching evidence
description: The one curated reference list behind the app's coaching rules — each source keyed AuthorYear with grade and access, and a rule table mapping each rule to code and its label (evidence-supported, heuristic or product choice)
tags: [architecture, run]
status: living
reviewed: 2026-10-01
code_refs:
  - app/Services/Run/Metrics/TrainingPaceCalculator.php
  - app/Services/Run/Metrics/VdotEstimator.php
  - app/Services/Run/Plan/IntensityPrescriptionResolver.php
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
