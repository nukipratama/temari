<?php

declare(strict_types=1);

use App\Enums\FallOffTilt;
use App\Enums\SessionType;
use App\Services\Run\Metrics\FallOffExponent;

it('tilts a fitted fall-off by its band', function (float $k, ?FallOffTilt $expected): void {
    expect(FallOffTilt::fromFallOff($k, true))->toBe($expected);
})->with([
    'at the clamp floor' => [FallOffExponent::MIN, FallOffTilt::Speed],
    'just above the floor' => [1.061, null],
    'in the neutral band' => [1.08, null],
    'at the endurance edge' => [FallOffTilt::ENDURANCE_ABOVE_K, null],
    'above the endurance edge' => [1.11, FallOffTilt::Endurance],
    'at the clamp ceiling' => [FallOffExponent::MAX, FallOffTilt::Endurance],
]);

it('never tilts a default fall-off or a missing one', function (?float $k): void {
    expect(FallOffTilt::fromFallOff($k, false))->toBeNull();
})->with([
    'up to 10K' => [FallOffExponent::DEFAULT_UP_TO_10K],
    'half' => [FallOffExponent::DEFAULT_HALF],
    'marathon' => [FallOffExponent::DEFAULT_MARATHON],
    'none' => [null],
]);

it('names the quality type and long-run factor each tilt leans to', function (): void {
    expect(FallOffTilt::Endurance->qualityType())->toBe(SessionType::Tempo)
        ->and(FallOffTilt::Speed->qualityType())->toBe(SessionType::Interval)
        ->and(FallOffTilt::Endurance->longRunFactor())->toBe(FallOffTilt::ENDURANCE_LONG_RUN_FACTOR)
        ->and(FallOffTilt::Speed->longRunFactor())->toBe(1.0);
});
