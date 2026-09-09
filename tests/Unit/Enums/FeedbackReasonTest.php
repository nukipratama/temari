<?php

declare(strict_types=1);

use App\Enums\FeedbackReason;
use App\Enums\FeedbackSubject;

it('files every reason under exactly one subject', function (): void {
    $narration = FeedbackReason::valuesFor(FeedbackSubject::Narration);
    $planDay = FeedbackReason::valuesFor(FeedbackSubject::PlanDay);

    expect($narration)->toBe(['facts_wrong', 'tone_off', 'too_long', 'ignores_my_plan'])
        ->and($planDay)->toBe(['wrong_day', 'too_hard', 'too_easy', 'wrong_pace'])
        ->and(array_intersect($narration, $planDay))->toBe([])
        ->and(count($narration) + count($planDay))->toBe(count(FeedbackReason::cases()));
});

it('offers every reason when the subject is not known yet', function (): void {
    expect(FeedbackReason::valuesFor(null))
        ->toHaveCount(count(FeedbackReason::cases()));
});
