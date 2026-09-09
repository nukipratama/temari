---
title: Flag this as wrong
description: A per-row "this is wrong" control on plan days and narrations, read by the owner in tinker.
tags: [feature, feedback]
status: living
reviewed: 2026-09-09
code_refs:
  - app/Models/Feedback.php
  - app/Enums/FeedbackSubject.php
  - app/Http/Controllers/FeedbackController.php
  - app/Http/Requests/StoreFeedbackRequest.php
  - resources/js/components/temari/FlagWrong.tsx
  - resources/js/components/temari/AnalysisStatus.tsx
  - resources/js/components/plan/WeekDayRow.tsx
  - routes/web.php
---

# Flag this as wrong

The two things the app asserts that a runner can disagree with are a day the plan prescribed and something Temari said about them. Both now carry a control that writes a row naming the exact thing it is about.

## What a row is

[Feedback](../../app/Models/Feedback.php) is deliberately thin: who, what they flagged, an optional note, and when. There is no status, no reply, and no `updated_at` — nothing reads these rows at runtime, so anything else would be a field that only ever rots.

`subject_type` is a [FeedbackSubject](../../app/Enums/FeedbackSubject.php) — `plan_day` (a `PlannedSession` id) or `narration` (an `Analysis` id). It is a small closed enum rather than an Eloquent morph because the repo registers no morph map and the two subjects need different ownership rules: a plan day is owned by its `user_id`, while an `Analysis` row has no `user_id` at all and its owner is resolved through [AnalysisSubjectAuthorizer](../../app/Services/AI/AnalysisSubjectAuthorizer.php), the same per-type check the trigger endpoint uses. The enum is registered with `typescript:enums`, so the frontend prop is the same closed set.

The note is optional and capped at `Feedback::MAX_NOTE_LENGTH` (280). Asking for an explanation before accepting a flag would cost most of the flags; the flag itself is the signal worth having.

## The endpoint

One route, `feedback.store` in [web.php](../../routes/web.php), inside the `auth` + `onboarded` group with `throttle:10,1` and `block-demo-telegram` — the guard is behaviourally generic (it blocks any demo mutation), so it keeps the shared sandbox from filling the table with visitor noise. The frontend hides the control for `is_demo` for the same reason, so the middleware is the defense-in-depth net rather than the visible rule.

Ownership lives in [StoreFeedbackRequest::authorize()](../../app/Http/Requests/StoreFeedbackRequest.php) rather than the controller, so a foreign subject id 403s instead of getting a validation redirect. An *unrecognised* subject type is deliberately left to the rules, so a typo reads as a 422 rather than a permission problem.

## The control

[FlagWrong](../../resources/js/components/temari/FlagWrong.tsx) is one component in three states — a ghost pill, an open note field, then a quiet `noted, thanks` — mounted twice:

- On every `done` narration block, from inside [AnalysisStatus](../../resources/js/components/temari/AnalysisStatus.tsx), so it follows narration wherever it renders (home, run detail, trends, plan) and inherits that block's `onSky` styling. A block that has no row yet (`analysis.id === null`) has nothing to flag, so it draws none.
- On the expanded plan day row in [WeekDayRow](../../resources/js/components/plan/WeekDayRow.tsx), labelled `flag this day` against the narration's `flag this read` — a wrong prescription and a wrong reading of it are different complaints.

It posts through `router.post` with `preserveState`, so the confirmation survives the redirect back.

## Reading the rows

There is no admin UI on purpose. Read them in tinker:

```bash
./vendor/bin/sail artisan tinker --execute 'App\Models\Feedback::with("user:id,name")->latest("id")->get(["id","user_id","subject_type","subject_id","note","created_at"])->each(fn ($f) => print("{$f->created_at} {$f->user->name} {$f->subject_type->value}#{$f->subject_id} {$f->note}\n"));'
```

## See also

[[plan-periodizer]] · [[recaps]]
