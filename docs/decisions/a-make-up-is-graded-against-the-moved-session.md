---
title: A make-up is graded against the moved session
description: A missed session made up within its week is graded against the session moved onto the run, never the advice shown that morning, and never teaches progression.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-07
code_refs:
  - app/Services/Run/Plan/SessionEditRules.php
  - app/Services/Run/Plan/MakeUpService.php
  - app/Services/Run/Plan/ComplianceScorer.php
  - app/Http/Controllers/PlanController.php
  - app/Services/Gamification/SeasonGamificationContext.php
  - database/migrations/2026_10_07_000000_add_make_up_columns_to_planned_sessions_table.php
---

# A make-up is graded against the moved session

**Status:** Accepted (2026-10-07). A documented exception to [[grading-follows-shown-advice-and-actual-stimulus]].

## Context

Move and Skip reached only days after today. An athlete who missed Tuesday's Easy and ran 5 km on Wednesday's rest day had no way to link the two: Tuesday stayed `missed` and Wednesday read as a rest day run anyway. Grading follows the advice shown before a run, and what Wednesday showed that morning was rest.

## Decision

- **Within the week, by hand.** [SessionEditRules](app/Services/Run/Plan/SessionEditRules.php) lets an unrun past day of the current Monday-to-Sunday week move onto a rest day of that week from today on, or onto an earlier rest day only where a run landed. A credited day is final, there is no retroactive Skip, and a miss never carries into the next week. Temari never pairs a run with a missed session on its own.
- **Hard-easy on every move.** A Long, Tempo, Interval or Race session never lands beside another of those, so a session can be left with nowhere to go and stays missed.
- **Graded against the moved session.** A move from a past day, or onto a day a run already landed on, is a make-up ([SessionEditRules::isMakeUp()](app/Services/Run/Plan/SessionEditRules.php)). [MakeUpService](app/Services/Run/Plan/MakeUpService.php) stamps the emptied day `made_up_on` with the date the session moved to, points the made-up day back at it through `made_up_from_id`, clears both days' clamp state and any skip the moved session carried, and regrades both in full. [ComplianceScorer](app/Services/Run/Plan/ComplianceScorer.php) skips the shown-advice lookup for either day: the made-up day is judged on distance and intent against the moved session, and the emptied day is graded as rest, so its old distance score leaves next week's adherence.
- **Declared after the run.** The made-up day's `intent_evidence` carries `advice_history: declared_after_run` and never `quality_progression: eligible`, so it teaches neither quality progression nor stimulus adherence.
- **Not honored rest.** The emptied day is not counted as honored rest in season goals ([SeasonGamificationContext](app/Services/Gamification/SeasonGamificationContext.php)), and its day panel says when it was made up.
- **Consequences reconciled.** The week is marked for reconciliation from the earlier day, both days' reads are re-requested after grading, the made-up day's run narration and card flavor are invalidated, and today's briefing is re-requested only when the make-up lands today. The demo athlete stays rule-based and makes no LLM call.

## Evidence

- **Make up within the week, otherwise drop it.** Short gaps cost little fitness: Feely et al. 2023 (Front Sports Act Living, doi:10.3389/fspor.2022.1096124) found a measurable cost only from breaks of 7 days or more, and Mujika & Padilla 2000 (Sports Med, doi:10.2165/00007256-200030020-00002) describe losses over weeks. The injury risk is in catching up by lengthening a run: Frandsen … Nielsen 2025 (BJSM, doi:10.1136/bjsports-2024-109380) found one run more than 10% longer than the longest of the past 30 days raised overuse injury (HRR 1.64–2.28). A make-up moves a session; it never lengthens or merges one.
- **No retroactive excuse, no progression from a make-up.** No study tests this directly; it is a product choice. The IOC load consensus (Soligard et al. 2016, BJSM, doi:10.1136/bjsports-2016-096581) treats accurate monitoring as the basis for prescription, and recall is unreliable: Dideriksen et al. 2016 (JSCR, doi:10.1519/JSC.0000000000001244) found individual error of −28% to +40%.
- **Hard-easy adjacency** rests mostly on mechanism and coaching convention (Bowerman). Stanley, Peake & Buchheit 2013 (Sports Med, doi:10.1007/s40279-013-0083-4): at least 48 h to recover after high-intensity work, 24–48 h after threshold work. Seiler 2010 (IJSPP, doi:10.1123/ijspp.5.3.276): hard sessions spread through a mostly easy week. Foster 1998 (MSSE, doi:10.1097/00005768-199807000-00023): monotony and strain track illness. Counting Long as hard is convention, consistent with the long-run spike data above.

## Consequences

A made-up day keeps its link while the week lasts: neither day can take part in another make-up, so a mistaken link is not undone in the app. The made-up day takes no Skip either, so moving a missed session onto today and skipping it never excuses the miss. A "missed because sick or injured" reason with an easy-only comeback week was considered and decided against.
