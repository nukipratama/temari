<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Backed enum for a planned session's row-level lifecycle. Every generated
 * row starts `Planned`; this is never mutated by regeneration or the
 * readiness clamp (both are render/generation-time concerns that leave the
 * stored row alone). `plan:score-compliance` (daily, see `routes/console.php`)
 * is what resolves a past `Planned` row to `Done`/`Partial`/`Missed`/
 * `Overreached`/`Skip` and writes it back — see
 * {@see \App\Services\Run\Plan\SessionMatcher::scoreFor()} for the km-ratio
 * bands that decide it. `PlanController`/`CurrentWeekPlanBuilder` read the
 * stored value directly; `SessionMatcher::statuses()` survives only as a
 * defensive live-compute fallback for a past row the daily command hasn't
 * reached yet.
 */
enum PlannedSessionStatus: string
{
    case Planned = 'planned';
    case Done = 'done';
    case Partial = 'partial';
    case Missed = 'missed';
    /** Ran significantly more than prescribed (score > 130) — a real signal, not a better Done. */
    case Overreached = 'overreached';
    /** Explicitly excused before the day passed ({@see \App\Models\PlannedSession::$skipped}) — never scored, never penalizes adherence. */
    case Skip = 'skip';

    /** Ran enough of the session (or more) for it to count toward the week's "showed up" count. */
    public function isCredited(): bool
    {
        return match ($this) {
            self::Done, self::Partial, self::Overreached => true,
            self::Planned, self::Missed, self::Skip => false,
        };
    }
}
