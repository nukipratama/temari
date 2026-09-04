<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * What started an LLM call, as opposed to which narrator answered it.
 *
 * `ai_token_usages.kind` names the narrator, so a `run_insight` row alone cannot
 * say whether it came from the ingest cascade, a user's "Reread" or the hourly
 * self-heal. This is the missing dimension, stamped onto the job at dispatch and
 * written beside `kind` when the call is metered.
 *
 * `Unknown` is the default rather than a guess: a dispatch site that forgets to
 * declare itself shows up as unattributed in the data instead of silently
 * inflating whichever origin happened to be the default.
 */
enum AnalysisOrigin: string
{
    case Scheduled = 'scheduled';
    case Ingest = 'ingest';
    case User = 'user';
    case Recovery = 'recovery';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Ingest => 'Ingest cascade',
            self::User => 'User-initiated',
            self::Recovery => 'Recovery',
            self::Unknown => 'Unattributed',
        };
    }
}
