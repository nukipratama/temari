<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Which producer wrote an Analysis row's content. Narration has two of them —
 * the LLM narrators and {@see \App\Services\AI\RuleBased\RuleBasedNarrationFiller}
 * — and the prose alone cannot say which one answered, so a capped day, a
 * content-filter fallback and a demo fill all read as ordinary narration.
 */
enum ServedBy: string
{
    case Llm = 'llm';
    case RuleBased = 'rule_based';

    public function label(): string
    {
        return match ($this) {
            self::Llm => 'LLM',
            self::RuleBased => 'Rule-based',
        };
    }
}
