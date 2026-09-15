---
title: Flag this as wrong
description: A per-row "this is wrong" control on plan days and narrations, read by the owner in tinker.
tags: [feature, feedback]
status: living
reviewed: 2026-09-16
code_refs:
  - app/Models/Feedback.php
  - app/Enums/FeedbackSubject.php
  - app/Enums/FeedbackReason.php
  - app/Actions/Feedback/ResolveFlaggedSubjectsAction.php
  - resources/js/components/ui/Sheet.tsx
  - app/Http/Controllers/FeedbackController.php
  - app/Http/Requests/StoreFeedbackRequest.php
  - resources/js/components/temari/FlagWrong.tsx
  - resources/js/components/temari/FlagSheet.tsx
  - resources/js/components/temari/AnalysisStatus.tsx
  - resources/js/components/plan/WeekDayRow.tsx
  - routes/web.php
  - app/Services/AI/AnalysisService.php
  - app/Http/Controllers/DevtoolsFeedbackController.php
  - resources/js/pages/DevtoolsFeedback.tsx
---

# Flag this as wrong

The two things the app asserts that a runner can disagree with are a day the plan prescribed and something Temari said about them. Both now carry a control that writes a row naming the exact thing it is about.

## What a row is

[Feedback](../../app/Models/Feedback.php) is deliberately thin: who, what they flagged, why, an optional note, when, and whether the thing it was about has since been replaced. There is no reply and no `updated_at` — nothing else is read at runtime, so anything more would be a field that only ever rots.

`reason` is a [FeedbackReason](../../app/Enums/FeedbackReason.php), and each case belongs to exactly one subject: `facts wrong` / `tone off` / `too long` / `ignores my plan` read a narration, `wrong day` / `too hard` / `too easy` / `wrong pace` read a prescription. The request validates the pair, not the value alone, so `tone_off` on a plan day is a 422. It is nullable because the rows written before reasons existed keep their null.

`subject_type` is a [FeedbackSubject](../../app/Enums/FeedbackSubject.php) — `plan_day` (a `PlannedSession` id) or `narration` (an `Analysis` id). It is a small closed enum rather than an Eloquent morph because the repo registers no morph map and the two subjects need different ownership rules: a plan day is owned by its `user_id`, while an `Analysis` row has no `user_id` at all and its owner is resolved through [AnalysisSubjectAuthorizer](../../app/Services/AI/AnalysisSubjectAuthorizer.php), the same per-type check the trigger endpoint uses. The enum is registered with `typescript:enums`, so the frontend prop is the same closed set.

The note is optional and capped at `Feedback::MAX_NOTE_LENGTH` (280). Asking for prose before accepting a flag would cost most of the flags; the reason is the signal worth having.

A unique index on `(user_id, subject_type, subject_id)` makes one flag per subject per athlete the shape of the table rather than a rule the controller remembers, so [FeedbackController](../../app/Http/Controllers/FeedbackController.php) keeps a second POST from a stale tab on the row already there instead of taking it as a second opinion. A superseded row is the exception: the control is back, so that row is rewritten in place with the new reason, note and time, the index leaving no room for a second one beside it.

## When the narration moves on

A narration flag names an `Analysis` row id, and a re-narration reuses that row — [AnalysisService::invalidateDoneRow()](../../app/Services/AI/AnalysisService.php) sends it Done → Pending in place. Nothing about the flag says which narration it read, so without more it outlives its subject: a flag filed against Tuesday's briefing would still stand against the text that replaced it, and the athlete, whose control is gone, could never flag the new one.

So the flag is retired where the text it was about is actually lost: [AnalysisService::markDone()](../../app/Services/AI/AnalysisService.php) stamps `superseded_at` on the row's open flags just as it archives the previous [AnalysisVersion](../../app/Models/AI/AnalysisVersion.php). That is one site for every path that replaces a narration — the per-block "Try again", a plan edit, an ingest, the devtools replay, the rule-based refill — rather than one per dispatcher, and it fires on the replacement rather than on the request for one. A first narration replaces nothing, so it supersedes nothing.

The row is marked, never deleted. A flag is the only thing the athlete has said about a narration, most re-narrations are routine (every ingest re-narrates the run's card flavor), and deleting on one would quietly empty the devtools list of flags nobody had read yet. `plan_day` flags are untouched by this: a prescription is not re-narrated in place — [Periodizer](../../app/Services/Run/Plan/Periodizer.php) deletes unpinned rows and writes new ones, so the new day carries a new id the athlete can flag on its own.

## The endpoint

One route, `feedback.store` in [web.php](../../routes/web.php), inside the `auth` + `onboarded` group with `throttle:10,1` and `block-demo-telegram` — the guard is behaviourally generic (it blocks any demo mutation), so it keeps the shared sandbox from filling the table with visitor noise. The frontend hides the control for `is_demo` for the same reason, so the middleware is the defense-in-depth net rather than the visible rule.

Ownership lives in [StoreFeedbackRequest::authorize()](../../app/Http/Requests/StoreFeedbackRequest.php) rather than the controller, so a foreign subject id 403s instead of getting a validation redirect. An *unrecognised* subject type is deliberately left to the rules, so a typo reads as a 422 rather than a permission problem.

## The control

[FlagWrong](../../resources/js/components/temari/FlagWrong.tsx) is one icon-only ghost button that opens a bottom sheet titled `something off?`: the subject's four reasons as single-select chips, an optional note, `send` (disabled until a reason is chosen) and `never mind`. There is no toast — the control itself goes away, which is the confirmation: an answered question stops being asked. It is mounted twice:

- On every `done` narration block, from inside [AnalysisStatus](../../resources/js/components/temari/AnalysisStatus.tsx), at the end of the `generated …` meta line under the paragraph, so it follows narration wherever it renders (home, run detail, trends, plan, the recaps) and inherits that block's `onSky` styling. It is drawn whether or not that block may be reread, and the `reread` pill keeps its own line below. A block that has no row yet (`analysis.id === null`) has nothing to flag, so it draws none; a `done` block with no `generated_at` draws the flag alone, right-aligned, on the line the meta text would have occupied. A meta line is ~16px and the tap target is 44px, so this placement passes `compact`: the icon draws on a 20px box and the 44px target is pushed back out with a pseudo-element.
- On the collapsed plan-day trigger row in [WeekDayRow](../../resources/js/components/plan/WeekDayRow.tsx), at its right edge, since a plan day carries no narration meta line of its own. It keeps the full 44px box there, that row already being taller. Labelled `flag this day` against the narration's `flag this read` — a wrong prescription and a wrong reading of it are different complaints. The trigger is itself a button, so the flag is its sibling in the row rather than nested inside it.

It posts through `router.post` with `preserveState`, so the confirmation survives the redirect back.

Only the icon and its state are on the first-paint path. The sheet and the form live in [FlagSheet](../../resources/js/components/temari/FlagSheet.tsx), behind a `lazy()` boundary mounted on the first tap, because FlagWrong renders from the shared app chunk and Base UI's dialog would otherwise be loaded by every route — the `base-ui` group in [vite.config.ts](../../vite.config.ts) is `entriesAware` so that split actually reaches the bundle.

## The sheet

[Sheet](../../resources/js/components/ui/Sheet.tsx) is a bottom sheet on Base UI's Dialog, which already owns the focus trap, the body scroll lock and the escape/outside-press dismissals. What sits on top of it is CSS and pointer events only: the slide-up is a `transition-transform` keyed off Base UI's own `data-starting-style` / `data-ending-style`, and swipe-to-dismiss is `pointerdown`/`move`/`up` on the grab bar against a `SWIPE_DISMISS_PX` threshold. No gesture library — and no animation library anywhere in the app any more, the sheet's `data-starting-style` transition being the pattern the rest of the entrances were rebuilt on.

## Knowing it is already flagged

No flag is drawn at all on a subject this athlete has already flagged, which means every narration payload and every plan-day payload carries a `flagged` boolean. [ResolveFlaggedSubjectsAction](../../app/Actions/Feedback/ResolveFlaggedSubjectsAction.php) is bound `scoped()`, so a page drawing seven plan days and a dozen narration blocks reads the athlete's flags once and answers all of them from that one set. It reads open flags only: a superseded one is about text that is gone, so the subject counts as unflagged and the control is back.

## Reading the rows

[DevtoolsFeedbackController](../../app/Http/Controllers/DevtoolsFeedbackController.php), behind the `devtools` middleware group in [web.php](../../routes/web.php) alongside `/devtools/design` and `/devtools/narration`, lists the last 200 rows newest first as [DevtoolsFeedback](../../resources/js/pages/DevtoolsFeedback.tsx): when, the runner's name, the flagged subject, the reason (lowercase, marked `superseded` when the narration has since been replaced), and the note (truncated, full text on hover). The athlete narration page carries the same mark on its own flag chip. It is read-only — no reply, no status change, matching the row shape above.

The subject link reuses [AnalysisMessagePresenter::url()](../../app/Services/Telegram/AnalysisMessagePresenter.php), the same resolver the tap-through notification already uses, rather than a second URL map: a `plan_day` subject links to `/plan`, a `narration` subject links through to the run/recap/month page when the presenter knows that analysis type, and reads as label-only text otherwise. A narration whose `Analysis` row is gone reads as "narration (deleted)".

Rows can still be read directly in tinker:

```bash
./vendor/bin/sail artisan tinker --execute 'App\Models\Feedback::with("user:id,name")->latest("id")->get(["id","user_id","subject_type","subject_id","reason","note","created_at"])->each(fn ($f) => print("{$f->created_at} {$f->user->name} {$f->subject_type->value}#{$f->subject_id} {$f->reason?->value} {$f->note}\n"));'
```

## See also

[[plan-periodizer]] · [[recaps]]
