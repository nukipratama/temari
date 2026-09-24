---
title: A pinned day still gets readiness advice
description: Pinning keeps a session out of regeneration, not out of today's readiness step-down, which is shown beside it but never recorded against it.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-24
code_refs:
  - app/Services/Run/Plan/PlanPageAssembler.php
  - app/Services/Run/Plan/CurrentWeekPlanBuilder.php
  - app/Services/Run/Plan/ClampNarrationContext.php
  - app/Services/Run/Plan/RestClampRecorder.php
  - app/Http/Controllers/PlanController.php
---

# A pinned day still gets readiness advice

## Context

Today's readiness clamp skipped any pinned row, and every move pins *both* sides of the swap ([PlanController](app/Http/Controllers/PlanController.php)). Moving a session onto today, or off it, silently switched off the fatigue advice for that day.

## Decision

A pinned today gets the same advisory step-down as any other day ([[todays-ease-stays-a-stepdown]]): the athlete's chosen session leads and the eased version sits beside it, with its explanatory line ([[the-clamp-explains-itself]]). [RestClampRecorder](app/Services/Run/Plan/RestClampRecorder.php) still never records an ease against a pinned row, so the athlete's explicit choice is never re-graded behind their back, and the week's redistribution still counts a pinned today at its full km.

## Why

Pinning says "this is the session I want on this day", not "don't tell me I'm tired". Advice costs nothing to show; recording it would override a deliberate choice.
