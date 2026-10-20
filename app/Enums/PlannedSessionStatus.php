<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Backed enum for a planned session's row-level lifecycle. Every generated
 * row starts `Planned`; `plan:score-compliance` (daily) resolves a past one to
 * `Done`/`Partial`/`Missed`/`Overreached`/`Skip` — see
 * {@see \App\Services\Run\Plan\SessionMatcher::scoreFor()} for the km-ratio
 * bands. `SessionMatcher::scoreRange()` survives only as a live-compute
 * fallback for a past row the daily command hasn't reached yet.
 */
enum PlannedSessionStatus: string
{
    case Planned = 'planned';
    case Done = 'done';
    case Partial = 'partial';
    case Missed = 'missed';
    /** Ran significantly more than prescribed (score >= 130), or ran it harder than the session asked — a real signal, not a better Done. */
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
