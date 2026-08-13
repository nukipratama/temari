<?php

declare(strict_types=1);

use App\Enums\TrendVerdict;

it('exposes the four outcomes the home screen can render', function (): void {
    expect(array_map(fn (TrendVerdict $case): string => $case->value, TrendVerdict::cases()))
        ->toBe(['improving', 'plateaued', 'slipped', 'not_enough_history']);
});

it('answers its own identity checks', function (): void {
    expect(TrendVerdict::Improving->isImproving())->toBeTrue()
        ->and(TrendVerdict::Improving->isPlateaued())->toBeFalse()
        ->and(TrendVerdict::Improving->isSlipped())->toBeFalse()
        ->and(TrendVerdict::Improving->isNotEnoughHistory())->toBeFalse()
        ->and(TrendVerdict::Plateaued->isPlateaued())->toBeTrue()
        ->and(TrendVerdict::Slipped->isSlipped())->toBeTrue()
        ->and(TrendVerdict::NotEnoughHistory->isNotEnoughHistory())->toBeTrue();
});

it('treats every outcome but the empty state as a judgement', function (): void {
    expect(TrendVerdict::Improving->isJudged())->toBeTrue()
        ->and(TrendVerdict::Plateaued->isJudged())->toBeTrue()
        ->and(TrendVerdict::Slipped->isJudged())->toBeTrue()
        ->and(TrendVerdict::NotEnoughHistory->isJudged())->toBeFalse();
});
