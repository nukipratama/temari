<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use NoDiscard;

/**
 * The hardest session intensity a runner should be encouraged toward today.
 * Computed deterministically by {@see Readiness} from load + recovery signals;
 * the LLM may voice a suggestion at or below the ceiling but never above it,
 * so a red-flag day can't be phrased as a quality day even if the prose wants.
 *
 * Ordered least-to-most permissive via {@see rank()} so the assessor can take
 * the most restrictive cap across every guardrail with a simple minimum.
 */
enum ReadinessCeiling: string
{
    case Rest = 'rest';
    case EasyOnly = 'easy_only';
    case ModerateOk = 'moderate_ok';
    case QualityOk = 'quality_ok';

    public function rank(): int
    {
        return match ($this) {
            self::Rest => 0,
            self::EasyOnly => 1,
            self::ModerateOk => 2,
            self::QualityOk => 3,
        };
    }

    /**
     * The more restrictive of two ceilings (lower rank wins). Used to fold each
     * guardrail's cap into a single ceiling.
     */
    #[NoDiscard]
    public function capTo(self $other): self
    {
        return $other->rank() < $this->rank() ? $other : $this;
    }
}
