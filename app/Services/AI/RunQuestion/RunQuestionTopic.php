<?php

declare(strict_types=1);

namespace App\Services\AI\RunQuestion;

/**
 * An angle this run's own data can actually support a question about.
 *
 * {@see RunQuestionSeeds} decides which of these a given run earns; the user is
 * free to ask anything, so this is a starting point, never the accepted set.
 */
enum RunQuestionTopic: string
{
    case HrDrift = 'hr_drift';
    case Decoupling = 'decoupling';
    case NegativeSplit = 'negative_split';
    case CadenceDrop = 'cadence_drop';
    case SlowestSplit = 'slowest_split';
    case HardZones = 'hard_zones';
    case Heat = 'heat';
    case Climb = 'climb';
    case Baseline = 'baseline';

    /** The suggested question, written the way the user would type it. */
    public function question(): string
    {
        return match ($this) {
            self::HrDrift => 'why did my heart rate drift up?',
            self::Decoupling => 'what does my decoupling say about my base?',
            self::NegativeSplit => 'how did I finish faster than I started?',
            self::CadenceDrop => 'why did my cadence fall off?',
            self::SlowestSplit => 'which km cost me the most?',
            self::HardZones => 'was this harder than it should have been?',
            self::Heat => 'how much did the heat cost me?',
            self::Climb => 'how much did the climbing slow me down?',
            self::Baseline => 'how does this one compare to my usual?',
        };
    }
}
