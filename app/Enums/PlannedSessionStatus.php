<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Backed enum for a planned session's row-level lifecycle. Every generated
 * row starts `Planned`; this is never mutated by regeneration or the
 * readiness clamp (both are render/generation-time concerns that leave the
 * stored row alone). {@see \App\Services\Run\Plan\SessionMatcher} derives
 * `Done`/`Partial`/`Missed` at render time for past dates by comparing the
 * km actually run against the km prescribed, without writing it back.
 */
enum PlannedSessionStatus: string
{
    case Planned = 'planned';
    case Done = 'done';
    case Partial = 'partial';
    case Missed = 'missed';

    /** Ran enough of the session for it to count toward the week's adherence. */
    public function isCredited(): bool
    {
        return match ($this) {
            self::Done, self::Partial => true,
            self::Planned, self::Missed => false,
        };
    }
}
