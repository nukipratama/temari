---
title: A manual edit may stack hard days, with a warning
description: A move or swap that leaves a hard session beside another hard day is offered with a warning naming that day instead of being refused; the periodizer's own spacing is unchanged.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-11-13
code_refs:
  - app/Services/Run/Plan/SessionEditRules.php
  - resources/js/components/plan/DayDetail.tsx
---

# A manual edit may stack hard days, with a warning

**Status:** Accepted (2026-11-13). Built in #2455.

A move used to refuse any rest day that would leave a Long, Tempo, Interval or Race session beside another of those, and a session could only land on a rest day. Putting a weekend long run on the other weekend day took up to three moves, and moving it next to a hard session was impossible even when the athlete meant it.

- **Training days swap directly.** From today on, an unrun session swaps with another unrun training day of its week in one edit ([SessionEditRules::moves()](app/Services/Run/Plan/SessionEditRules.php)). Race days, made-up days, days a run landed on, the day carrying the week's taken override and past days never swap; past days keep the make-up flow. An override is stored by date on the week's adaptation, so swapping its day would leave it on the other session.
- **Hard neighbours warn, they don't refuse.** A target that leaves either swapped session beside another hard day stays offered. The day ships it in `move_warnings`, and [DayDetail](resources/js/components/plan/DayDetail.tsx) outlines the cell and names the hard day it would sit beside.
- **The plan's own spacing is unchanged.** The periodizer still lays hard days at least two days apart, and no override shortens that ([[a-hard-day-dropped-session-can-be-brought-back]]).

**Why a hand edit may do what the plan never does.** Hard days stay apart because recovery after work above the first ventilatory threshold takes longer than after easy running (Seiler et al., *Med Sci Sports Exerc* 2007;39(8):1366–1373). That is the right default for a plan the athlete did not choose day by day. A manual edit is a deliberate choice, usually forced by life (work, travel, a group run). A hard refusal pushed athletes to route around it through rest days, which made the result harder to read without making it any safer. The warning keeps the risk in view at the moment of choice.

Rejected: keeping the refusal for moves and only warning on swaps (two rules for edits that look the same), and a confirm dialog (an extra tap on every warned target, for a risk already named on the cell).
