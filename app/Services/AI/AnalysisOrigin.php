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
 *
 * Also reused, cases below `Return`, as {@see \App\Models\AI\Analysis::$rule_based_reason}:
 * why the rule-based filler answered instead of the LLM, a different axis than
 * dispatch origin but the same "value on a nullable column" shape.
 */
enum AnalysisOrigin: string
{
    case Scheduled = 'scheduled';
    case Ingest = 'ingest';
    case User = 'user';
    case Recovery = 'recovery';
    case Replay = 'replay';
    case Return = 'return';
    case Unknown = 'unknown';
    case Demo = 'demo';
    case Capped = 'capped';
    case DeadLetter = 'dead_letter';
    case ContentFilter = 'content_filter';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Ingest => 'Ingest cascade',
            self::User => 'User-initiated',
            self::Recovery => 'Recovery',
            self::Replay => 'Replay',
            self::Return => 'Athlete return',
            self::Unknown => 'Unattributed',
            self::Demo => 'Demo account',
            self::Capped => 'Daily ceiling reached',
            self::DeadLetter => 'Dead-lettered block',
            self::ContentFilter => 'Content filter fallback',
        };
    }
}
