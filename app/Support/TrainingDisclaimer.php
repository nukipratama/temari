<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The single wording of "these numbers are guidance, not medicine", so the Plan
 * tab and the public legal pages cannot end up saying it two different ways.
 * Mirrors {@see DataUseStatement}, which does the same job for AI data use.
 */
final class TrainingDisclaimer
{
    public const string HEADLINE = 'Training guidance, not medical advice';

    public const string TEXT = 'Temari prescribes from your own data, not from a medical assessment. These numbers are training guidance, not medical advice. Pain, illness or injury is a conversation for a doctor, not a plan engine.';

    /**
     * The scope of what the plan engine can and cannot see, for the standalone
     * page. The Plan tab shows {@see self::HEADLINE} and {@see self::TEXT} and
     * links here for the rest; it sits beside the numbers it qualifies, so it
     * needs no expansion inline.
     *
     * @return list<string>
     */
    public static function scope(): array
    {
        return [
            'The plan is arithmetic over what you have already run: your recent volume, how much of last week you actually completed, your readiness and load signals, and your race goal if you set one. Nothing else goes into it.',
            'It cannot see an injury, an illness, a medication, a bad night, or any training you did that never reached Strava. When one of those is in play, the plan is working from a picture it knows is incomplete.',
            'The same holds for everything Temari writes. The notes are generated from your numbers, not from an assessment of you, and they are not reviewed by anyone before you read them.',
        ];
    }
}
