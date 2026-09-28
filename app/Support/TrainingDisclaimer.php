<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The single wording of "these numbers are guidance, not medicine", so the Plan
 * tab, the login page and the public legal pages cannot end up saying it two
 * different ways.
 * Mirrors {@see DataUseStatement}, which does the same job for AI data use.
 */
final class TrainingDisclaimer
{
    public const string HEADLINE = 'training guidance, not medical advice';

    public const string SHORT = 'temari plans from your runs, not a check-up. if something hurts, rest and see a pro.';

    public const string TEXT = 'Temari plans from your own runs, not from a medical assessment. These numbers are training guidance, not medical advice. If something hurts, or you are ill or injured, that is one for a doctor, not the plan.';

    /**
     * The scope of what the plan engine can and cannot see, for the standalone
     * page. The Plan tab and the login page show {@see self::SHORT} with a link
     * here.
     *
     * @return list<string>
     */
    public static function scope(): array
    {
        return [
            'The plan is arithmetic over what you have already run: your recent volume, how much of last week you actually completed, your readiness and load signals, and your race goal if you set one. Nothing else goes into it.',
            'It can\'t see an injury, an illness, a medication, a bad night, or any training you did that never reached Strava. When one of those is in play, the plan is working from a picture it knows is incomplete.',
            'The same holds for everything Temari writes. The notes come from your numbers, not from an assessment of you, and nobody checks them before you read them.',
        ];
    }
}
