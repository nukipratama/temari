<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * What started an LLM call, as opposed to which narrator answered it. It is stamped onto the job at
 * dispatch and written beside `ai_token_usages.kind` when the call is metered.
 *
 * `Unknown` is the default rather than a guess, so a dispatch site that forgets to declare itself
 * shows up as unattributed.
 *
 * Cases below `Return` also serve as {@see \App\Models\AI\Analysis::$rule_based_reason}: why the
 * rule-based filler answered instead of the LLM.
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
    case ContentFilter = 'content_filter';
    case RejectedOutput = 'rejected_output';

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
            self::ContentFilter => 'Content filter fallback',
            self::RejectedOutput => 'Rejected output fallback',
        };
    }
}
