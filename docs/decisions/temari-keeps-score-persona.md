---
title: Temari becomes a training partner who keeps score
description: The persona shifts from a soft warm friend to a training partner who holds up the runner's own numbers and names a coast; supersedes the friend-persona voice stance, including the one carried by the thread-ball rebrand.
tags: [decision, design]
status: accepted
reviewed: 2026-08-13
code_refs:
  - app/Services/AI/TemariPersona.php
  - app/Services/AI/StructuredChatCaller.php
  - app/Services/AI/Narrators/BriefingMascotVoiceNarrator.php
  - app/Services/AI/RuleBased/RuleBasedNarrationFiller.php
---

# Temari becomes a training partner who keeps score

**Status:** Accepted (documented 2026-08-13). Supersedes the friend-persona voice stance, which lived in [[voice-and-tone]] and in the persona prompt rather than in any ADR of its own, and the persona half of [[thread-ball-character-rebrand]].

## Context

Temari shipped as "a friend who runs alongside you": warm, casual, and explicitly non-evaluative. The old persona prompt closed its constraint list with `NEVER judge. I keep them company, I don't grade them.` Every narrator inherited that stance, because the persona is prepended to every LLM call at [StructuredChatCaller.php:69](../../app/Services/AI/StructuredChatCaller.php#L69).

That produced a companion that was pleasant and inert. Three failures followed from the same root:

- **The app's own premise went unspoken.** The product is built entirely on you-versus-your-past-self: a 28-day baseline, `past_you` similar-session matching, week-over-week snapshots, a PR ladder. The narration had all of it available and used it as decoration rather than as a verdict.
- **Praise was issued rather than earned.** Encouragement was already marked optional in the prompt, but the model closed on a warm line anyway, because nothing in the register told it that a block could simply end. Constant praise is indistinguishable from no praise.
- **Coasting was unsayable.** A runner going flat for a month while fully recovered got the same warm reassurance as a runner deep in overreach. The one observation most likely to change behavior was the one the persona forbade.

The friend framing itself was never the problem, and is retained. What was wrong was equating friendship with never having an opinion.

## Decision

**Temari is a training partner who keeps score.** Still warm, now competitive about the runner's own numbers, and willing to name it when they are coasting. The full register lives in the `SYSTEM_PROMPT` at [TemariPersona.php:29](../../app/Services/AI/TemariPersona.php#L29); it is the single source of truth and this note does not restate it.

- **Three new prompt sections carry the shift.** `# Keeping score` ([TemariPersona.php:57](../../app/Services/AI/TemariPersona.php#L57)) enumerates the legal scoreboard axes and requires naming the number and the direction, including when the direction is bad. `# Calling a coast` ([TemariPersona.php:80](../../app/Services/AI/TemariPersona.php#L80)) licenses the honest read once, plainly, and pairs it with a hard exclusion list. `# Praise is earned, never issued` ([TemariPersona.php:105](../../app/Services/AI/TemariPersona.php#L105)) reframes encouragement as a scarce currency and states that a block with nothing to praise is already finished.
- **The exclusion list is the load-bearing half of the coast rule.** Fatigue, overreaching, high strain or monotony, heat, a plan-called rest, and the first run back after a break are never a coast. Without it the register degrades into scolding a tired runner, which is worse than the inert companion it replaced. The mood calibration reinforces it: a bad day is off the scoreboard entirely.
- **`NEVER judge` is deleted, not softened.** It is the direct negation of the new register. Its replacement draws the line where it actually belongs: `I have opinions, and I keep them about the numbers. NEVER about the person.`
- **Every pre-existing safety constraint is retained verbatim.** No comparison to other runners (now strengthened with an explicit ban on averages, percentiles, population norms, and leaderboards), no medical authority, no internal field names in output, the number-formatting rules, the single-bold rule, and the em-dash ban. Keeping score explicitly stops at the point where a body might be hurt.
- **Full English, lowercase-leaning, as a soft tendency.** Sentences lean lowercase the way people type to someone they know well, but the prompt states outright that it is a habit and not a rule, breakable for rhythm. Output will vary between blocks, and that variance is accepted rather than treated as drift. This deliberately does **not** extend to UI chrome, which stays Title Case.
- **Emoji tighten to one per output, default zero, PRs and first-evers only** ([TemariPersona.php:217](../../app/Services/AI/TemariPersona.php#L217)). The product already carries celebration visually through the mascot's expression states, rarity cards, and auras, so a text emoji says a second time what the screen already said.
- **Both producers move together.** The LLM narrators and the deterministic [RuleBasedNarrationFiller](../../app/Services/AI/RuleBased/RuleBasedNarrationFiller.php) that serves the demo account and the unconfigured-Azure path were re-voiced in the same change. Splitting them would leave the public demo speaking as the old persona while every real account spoke as the new one.
- **Deterministic fallback copy counts as persona surface.** The four hardcoded ceiling-violation strings at [BriefingMascotVoiceNarrator.php:307](../../app/Services/AI/Narrators/BriefingMascotVoiceNarrator.php#L307) ship to users whenever the model exceeds its readiness ceiling, so they were rewritten alongside the prompts rather than left as a visible register fork.
- **Three narrators gained comparison tools.** Post-run speech, card flavor, and the featured-card voice previously had no way to reach a past number, which would have left them structurally softer than the persona promises. They now carry week-state, personal-records, and effort-context reads respectively.

## Consequences

- **Enables:** narration that uses the metrics the app already computes as an argument rather than as trim; an honest read on a plateau, which is the moment a training companion is worth having; praise that means something because it is rationed.
- **Costs:** a coordinated pass across all nine narrator prompts, both producers, the voice doc, and the skill, because the persona is prepended to every call and a partial rewrite reads as drift. Per-narrator prompts that re-defined voice locally had to be stripped rather than re-voiced. The three widened toolboxes spend more tokens per block; the AI budget is unconstrained by decision, so this was accepted rather than optimized.
- **Risk accepted:** the coast rule is the sharpest edge in the persona and depends on the model correctly reading the exclusion list. The mood calibration and the exclusion list are redundant on purpose. This is the thing to watch in the manual voice spot-check.
- **Not done:** no UI chrome re-casing, no change to the mascot's form, poses, accessories, rarity ladder, or the Threadwork palette, and no i18n layer (the product is hard full-English).

## See also

- [[voice-and-tone]] — the register, vocabulary policy, and number rules in full.
- [[thread-ball-character-rebrand]] — the character's visual identity, which this note leaves intact.
- [[temari-mascot]] — the mascot's rendering and pose vocabulary.
