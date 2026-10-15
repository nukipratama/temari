---
title: The post-run speech is checked before it is saved
description: A post-run speech that quotes a pace, distance or share no tool returned, or turns the day's verdict around, is re-asked once and then replaced by the rule-based line.
tags: [decision, ai, run]
status: accepted
reviewed: 2026-10-15
code_refs:
  - app/Services/AI/Narrators/SpeechFactCheck.php
  - app/Services/AI/Narrators/PostRunSpeechNarrator.php
  - app/Services/AI/StructuredChatCaller.php
  - app/Exceptions/AI/RejectedOutputException.php
  - app/Jobs/AI/AnalyzeGroupJob.php
  - app/Services/AI/AnalysisOrigin.php
---

# The post-run speech is checked before it is saved

**Status:** Accepted (2026-10-15)

## Context

[[run-narrators-share-the-days-verdict]] tells the run narrators, in their prompts, to repeat the day's stored verdict and to quote only numbers their tools returned. A prompt is a request, not a guarantee. The run insight already has a code gate (claims whose anchor doesn't resolve are dropped), and so do the post-run briefing and the profile voice. The post-run speech had none. In prod it compared a tempo's whole-run average with the threshold target and called the run "easier than written", the opposite of the stored verdict. A model's own subtraction ("0.9 km over the target") is another way a speech states a figure nothing measured.

## Decision

**The speech passes [SpeechFactCheck](app/Services/AI/Narrators/SpeechFactCheck.php) before it is saved:**

- **Figures.** Every pace (`m:ss`), distance (`… km`) and share (`…%`, `… percent`) the speech quotes must be a figure one of its tools returned, compared through [QuotedFigures](app/Services/AI/Narrators/QuotedFigures.php) and allowed at the precision a sentence quotes it (7.33 reads as "7.3 km", 60.2 as "60%"). Other numbers (temperatures, week counts) are left to the prompt, so a routine sentence is not rejected over a figure the decision never covered. The facts are read again from the speech's own tools in [PostRunSpeechNarrator](app/Services/AI/Narrators/PostRunSpeechNarrator.php), with database reads only and no model call.
- **Direction.** A sentence that sets the run against the plan ("easier than written", "harder than the plan asked") must agree with the day's stored `intent_verdict`:
  - too hard rules out *easier than*;
  - missed rules out *harder than*;
  - a hit rules out both;
  - an unjudged day or no plan checks nothing.

  Comparisons with anything but the ask ("harder than it looked") are not read.

**A failed check is re-asked once**, through the existing `ChatCallOptions` validator. If the rewrite fails too, [StructuredChatCaller](app/Services/AI/StructuredChatCaller.php) throws [RejectedOutputException](app/Exceptions/AI/RejectedOutputException.php), a subclass of `UnavailableException`, so every other caller keeps its terminal behaviour. [AnalyzeGroupJob](app/Jobs/AI/AnalyzeGroupJob.php) then fills the still-pending rows rule-based, the same path as the content-filter fallback. The run insight, already saved as model-written, stays. The row records `rule_based_reason = rejected_output`, which the narration dashboard's rule-based ledger shows in alarm colour beside `content_filter`.

Falling back rather than failing is deliberate. A failed row is retried by the hourly self-heal, which re-bills a call that would most likely be rejected again for the same reason. The rule-based line is plain but never wrong.

## Consequences

- At most one extra model turn per rejected speech (the rewrite runs with tools forbidden), plus one re-read of the speech's tools.
- The lexicon is a heuristic: a contradiction worded differently still passes, and the prompt rule stays the first line of defence.
- A time of day reads as a pace. Every `h:mm:ss` a tool returned also counts as `h:mm`, so the start time is accepted to the minute ("6:00" for `06:00:00`), and a pace that happens to equal it passes too. An afternoon start is accepted on a 12-hour clock as well ("6:30" for `18:30:00`), widening that pass to evening minutes.
- Only the activity group catches the new exception. The post-run briefing and the profile voice validators keep failing terminally on a second rejection, as before.
