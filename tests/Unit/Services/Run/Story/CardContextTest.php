<?php

declare(strict_types=1);

use App\Services\Run\Story\CardContext;

it('carries the whole-history facts it was built with', function (): void {
    $context = new CardContext(
        isFirstRunEver: true,
        isFirstDistanceBracket: false,
        weeklyConsistency: true,
        consecutiveDaysBefore: 4,
        athleteMaxHr: 190,
    );

    expect($context->isFirstRunEver)->toBeTrue()
        ->and($context->isFirstDistanceBracket)->toBeFalse()
        ->and($context->weeklyConsistency)->toBeTrue()
        ->and($context->consecutiveDaysBefore)->toBe(4)
        ->and($context->athleteMaxHr)->toBe(190);
});

it('allows a null athlete max HR for a run without average HR', function (): void {
    $context = new CardContext(false, false, false, 0, null);

    expect($context->athleteMaxHr)->toBeNull();
});
