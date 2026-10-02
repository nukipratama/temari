---
title: No automatic mileage debt
description: Missed or readiness-reduced kilometres never inflate future workouts; actual surplus may reduce remaining easy volume.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-01
code_refs:
  - app/Services/Run/Plan/CurrentWeekVolumeProjector.php
  - app/Services/Run/Plan/VolumeRedistributor.php
supersedes: easy-running-absorbs-the-week
---

# No automatic mileage debt

**Status:** Accepted (2026-10-01), settled with the owner as [#1499](https://github.com/nukipratama/temari/issues/1499).

## Context

The earlier easy-running rule allowed an incomplete week to enlarge the easy sessions that remained. A missed workout is not a debt to cram into the rest of the week.

## Decision

Only actual surplus may reduce future easy volume, with each remaining easy run kept at or above 70% of its original ask. Missed or readiness-reduced kilometres never enlarge a later run. Pinned sessions, today's fixed session, and future Long, Tempo and Interval sessions stay at their own effective distances. Any unused shortfall is written off and volume never crosses a week boundary.

[CurrentWeekVolumeProjector::project()](app/Services/Run/Plan/CurrentWeekVolumeProjector.php#L27) reuses the activity-date read for Home and Plan; [VolumeRedistributor::redistribute()](app/Services/Run/Plan/VolumeRedistributor.php#L18) caps each scale at the original ask.

## Consequences

- Home and Plan show the same current-week workout types, distances, segments and total.
- A page render does not write the resized distance to the saved session.
- A missed or reduced session never adds kilometres to a later day or week.

## See also

- [[easy-running-absorbs-the-week]] — key-session sizing and surplus reduction; its shortfall top-up is superseded here
- [[plan-periodizer]] — shared current-week rendering
