---
title: The Plan page leads with this week
description: The Plan page is rebuilt around this week at a glance, as a labelled day strip with a day panel, under a one-line season band, replacing the prototype-parity nested timeline.
tags: [decision, design]
status: accepted
reviewed: 2026-09-24
code_refs:
  - resources/js/pages/Plan.tsx
  - resources/js/components/plan/SeasonHeaderCard.tsx
---

# The Plan page leads with this week

> **Fact update, 2026-09-24.** The desktop width cap was dropped: a narrower Plan column read as
> inconsistent beside every other page, so Plan uses the shared page container width. The decision
> below is otherwise unchanged.

## Context

The prototype-parity program (`PS4`, decisions P22–P24) gave the Plan page one nested timeline: a season card, then a rail of week cards, with the current week open onto a volume chart and seven stacked day rows. The owner's review (#1143) found three faults:

- **Too long.** This week alone ran about three phone screens.
- **Cluttered top.** Title, regenerate, a sentence of race context, a schedule/race-goal tab switch and the full season card all came before the week.
- **No hierarchy.** What today asks for and how the week is going had the same weight as everything else.

## Decision

The options were rendered as pictures and the owner picked each one:

- **This week leads.** A one-line **season band** (week N of M · phase · adherence, plus the phase ribbon) sits under a compact header. Tapping the band opens the phase bars, the under-ready line and the season's Temari take.
- **Compact header.** The title and a one-line race summary with a "race goal" link, which replaces the schedule/race-goal tabs. Regenerate is an icon button that keeps its cooldown. The training disclaimer is a one-line footer linking to the full statement.
- **This week as a labelled day strip.** Seven tiles (day, km, a word; today filled, missed outlined, done tinted) sit under the week's adaptation note, shown in full. A **day panel** below them carries what a day row used to expand to. It opens on today.
- **Other weeks through a compact list.** Tapping one of the loaded weeks swaps it into the strip, and a pill returns to this week. Weeks outside the loaded window (3 back, 4 ahead) are plain summary rows, so the backend payload is unchanged.
- **Desktop** is the same single column, capped in width.

Every rule the timeline carried still holds: rules own every number, Move is a same-week swap onto a rest day, a day's narration appears only once it is credited, the clamp step-down sits beside the prescription, and the ribbon prints no numbers.

## Rejected

- **A compact day list** (seven one-line rows expanding inline). Closest to the old page, but it keeps the stacked shape the length came from.
- **A week pager driven by the ribbon.** Novel, but it hides the weeks list, and jumping far means many taps.
- **Today's session, the season arc or the race countdown as the hero.** Today's session already leads the Home page. The arc changes at most weekly. A self-scaled season has no countdown.
- **Volume bars or calendar dots as the day tiles.** Bars sized by km read well, but they hide what kind of day it is. Dots say nothing without a tap.
- **A two-column desktop layout.** It would give the page a second shape to maintain for little gain.

## Consequences

- `PlanRaceTabs` leaves the Plan page; the Race page keeps it.
- The Plan page no longer receives the disclaimer body (`TrainingDisclaimer::TEXT`); `/training-disclaimer` and `/terms` still carry it.
- The week volume chart, `SeasonTimeline`'s rail and its behind/ahead clusters go away as the strip and the weeks list land.
