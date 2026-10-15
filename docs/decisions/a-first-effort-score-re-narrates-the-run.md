---
title: A first effort score re-narrates the run
description: The post-run speech and run insight read the athlete's effort score against the CR-10 range the day's session usually feels, and the first score logged on a narrated run re-narrates it once.
tags: [decision, ai, run]
status: accepted
reviewed: 2026-10-15
code_refs:
  - app/Enums/SessionType.php
  - app/Services/AI/Agent/Tools/PerceivedEffortTool.php
  - app/Actions/AI/RenarrateAfterEffortScore.php
  - app/Jobs/AI/RecheckEffortScoreNarrationJob.php
  - app/Http/Controllers/PerceivedEffortController.php
  - app/Services/AI/MaterialFingerprint.php
  - app/Services/AI/Narrators/PostRunSpeechNarrator.php
  - app/Services/AI/Narrators/RunInsightNarrator.php
---

# A first effort score re-narrates the run

**Status:** Accepted (2026-10-15). Supersedes, for narration only, the "no coaching use" half of [[an-effort-score-is-collected-on-every-run]] and the 2026-10-06 "not planned" ruling on #1577. Readiness, plan adjustment and load still read no effort score.

## Context

An athlete can give any run an effort score out of 10 ([[an-effort-score-is-collected-on-every-run]]). The run narration ignored it: no narrator read `activity_details.perceived_effort`, and the narration runs when the run syncs, before the athlete has had a chance to score it. So the one block that speaks to how a run felt never heard how it felt. For the owner's runs in prod, scores of 2, 5 and 6 sat beside narration that never mentioned them. The owner decided on 2026-10-15 (#2138, decision 7) that the score should reach the narration.

## Decision

**The speech and the insight read the score against a range.** [PerceivedEffortTool](app/Services/AI/Agent/Tools/PerceivedEffortTool.php) (`get_perceived_effort`) returns:
- the score;
- the day's settled planned type (`PlannedSessionTypes::forRun()`);
- that type's range from [SessionType::perceivedEffortTarget()](app/Enums/SessionType.php);
- a relation word (below, within or above), never a signed gap.

The speech says once how the score sat against the range, and names a disagreement with the day's verdict as two readings, the watch and the feel. The insight uses the score only to read a reading, since it has no anchor of its own.

**The ranges (CR-10 session RPE):**

| Session | Range | Basis |
|---|---|---|
| Easy | 2–4 | Zone 1 of the three-zone model, at or below the first ventilatory threshold: session RPE ≤ 4 ([[coaching-evidence#SeilerKjerland2006]]) |
| Long | 3–5 | Zone 1 effort, lifted one point because a session RPE rates the whole session and duration adds to it ([[coaching-evidence#Foster2001]]) |
| Tempo | 6–7 | Around the second ventilatory threshold, which falls between session RPE 6 and 7 ([[coaching-evidence#SeilerKjerland2006]]) |
| Interval | 8–9 | Zone 3, session RPE ≥ 7; 10 is kept for maximal efforts |
| Race, Rest | none | A race is run all out; a rest day is not run |

The zone cut-offs are published, but they were derived in junior cross-country skiers ([[coaching-evidence#SeilerKjerland2006]]), not runners. The exact bounds inside each zone, and the long-run lift, are **heuristic**. Session RPE as a valid whole-session effort measure is evidence-supported ([[coaching-evidence#Foster2001]], [[coaching-evidence#Haddad2017]]).

**The first score re-narrates once.** [MaterialFingerprint](app/Services/AI/MaterialFingerprint.php) carries whether a score exists (`has_effort_score`), not its value. After saving a score, [PerceivedEffortController](app/Http/Controllers/PerceivedEffortController.php) calls [RenarrateAfterEffortScore](app/Actions/AI/RenarrateAfterEffortScore.php). When the run's speech is done and was stamped with a different fingerprint, it requests the activity group, invalidating it, after `AnalysisService::PLAN_EDIT_DELAY_SECONDS`. Editing the score, or clearing it and logging it again, matches the stamped fingerprint and requests nothing. A speech still queued picks the score up on its own. A speech being written when the score lands was read without it, so the action schedules one [RecheckEffortScoreNarrationJob](app/Jobs/AI/RecheckEffortScoreNarrationJob.php) `RECHECK_DELAY_SECONDS` later, which runs the same check once more and never schedules another. The demo athlete is never narrated.

## Consequences

- Each first score on a narrated run costs one speech and one insight generation, under the athlete's daily cost ceiling.
- The new key changes every fingerprint stamped before this shipped, scored or not. Such a narration re-narrates once at its next ingest-time refresh or the next score saved on its run. A rule-based narration, never stamped, is left alone.
- A run card's flavor is stamped with the same fingerprint but does not read the score, so after a first score it re-narrates once at the run's next re-sync.
- With no plan for the day, or two runs on it, the score has no range and the narration reports it plainly.
