---
title: A narrator gets citations when its prose already names things the page draws
description: Which narrator carries inline citations is decided by measuring what its real output talks about, not by picking the block that seems most factual. Measurement moved T6 S2 off post_run_speech onto the daily briefing.
tags: [decision, ai, ui]
status: accepted
reviewed: 2026-09-08
code_refs:
  - app/Services/AI/Anchor/AnchorKind.php
  - app/Services/AI/Anchor/DayAnchorResolver.php
  - app/Services/AI/Anchor/CitationValidator.php
  - app/Services/AI/Narrators/BriefingMascotVoiceNarrator.php
  - resources/js/components/temari/Citation.tsx
  - resources/js/lib/anchors.ts
---

# A narrator gets citations when its prose already names things the page draws

**Status:** Accepted (decided 2026-09-08)

## Context

A citation is a span of narration that points at the element proving it. The mechanism was
built for the per-run claims block, where every claim already carried a validated anchor.
Extending it to *prose* meant choosing which prose narrator goes first, and the obvious
answer was the post-run speech: it is the most factual block, and it sits on the page that
already draws splits, zones and derived metrics.

That answer was wrong, and only measurement showed it.

Across every completed `post_run_speech` row, restricted to the ones that are genuine model
output rather than rule-based filler, the anchorable hit rate is roughly **one in six** — and
most of those are a single stock phrase about climbing. Zones, which the run page now draws,
are named in **none** of them. What the speeches overwhelmingly talk about is the week's
volume, the weather and a heart-rate delta in bpm, none of which the anchor grammar covers
and one of which the run page does not draw at all.

The daily briefing, measured the same way, names the prescribed session in **every** block,
the week's volume in three of four, and a concrete pace target in half.

## Decision

**Citations go to the narrator whose prose already names things the page draws, established
by reading its real output, not by reasoning about which block sounds most factual.**

Three consequences follow.

1. **The anchor grammar grows one kind at a time, in the slice that needs it.**
   `AnchorKind::Session` was added for the briefing; no speculative kinds were added
   alongside it. The grammar has one definition site and exports to TypeScript, so a kind
   added in PHP fails the client build until the client handles it.
2. **Resolution is per subject, not global.** `RunAnchorResolver` answers for a run's own
   stream; `DayAnchorResolver` answers for a date. A run has no prescribed session and a day
   has no splits, and each resolver says so explicitly rather than falling through.
3. **A bad citation degrades, it never fails.** `CitationValidator` unwraps back to plain
   prose any citation whose anchor does not resolve, and any citation past the first, then
   stores the block as normal. The client independently drops one that resolves but that the
   page draws no element for. A block is never lost, and a control is never offered for
   something the reader cannot be shown.

## Consequences

- Adding citations to a new narrator starts with **measuring that narrator's real output**.
  A binding added without that measurement is the failure this decision exists to prevent.
- The briefing's fluent terms — the prescribed session, the week's volume, a pace target —
  are day-scoped, so the citable set on Home is *not* the run page's set. They happen to sit
  outside the week-stats disclosure, so revealing a collapsed target is not needed here; a
  later slice citing something inside it would have to solve that, because the collapsible is
  uncontrolled from mount and ignores an `open` supplied later.
- The measurement is a snapshot of one athlete's history and a small number of briefings.
  It should be re-run before the grammar is widened, not treated as settled.
