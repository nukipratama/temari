<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a runner flagged something. Each reason belongs to exactly one subject:
 * a wrong prescription and a wrong reading of one are different complaints, so
 * they get different words rather than a shared "it's wrong".
 */
enum FeedbackReason: string
{
    case FactsWrong = 'facts_wrong';
    case ToneOff = 'tone_off';
    case TooLong = 'too_long';
    case IgnoresMyPlan = 'ignores_my_plan';
    case WrongDay = 'wrong_day';
    case TooHard = 'too_hard';
    case TooEasy = 'too_easy';
    case WrongPace = 'wrong_pace';

    public function subject(): FeedbackSubject
    {
        return match ($this) {
            self::FactsWrong, self::ToneOff, self::TooLong, self::IgnoresMyPlan => FeedbackSubject::Narration,
            self::WrongDay, self::TooHard, self::TooEasy, self::WrongPace => FeedbackSubject::PlanDay,
        };
    }

    /**
     * @return list<string>
     */
    public static function valuesFor(?FeedbackSubject $subject): array
    {
        $cases = $subject === null
            ? self::cases()
            : array_filter(self::cases(), fn (self $reason): bool => $reason->subject() === $subject);

        return array_values(array_map(static fn (self $reason): string => $reason->value, $cases));
    }
}
