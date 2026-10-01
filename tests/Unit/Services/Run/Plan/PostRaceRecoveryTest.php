<?php

declare(strict_types=1);

use App\Services\Run\Plan\PostRaceRecovery;
use Illuminate\Support\Carbon;

it('sizes the quality-free window by the distance actually run', function (float $distanceRunM, string $noQualityThrough, ?string $deloadWeekStart): void {
    $recovery = PostRaceRecovery::after(Carbon::parse('2026-10-31'), $distanceRunM);

    expect($recovery->noQualityThrough->toDateString())->toBe($noQualityThrough)
        ->and($recovery->deloadWeekStart?->toDateString())->toBe($deloadWeekStart);
})->with([
    'marathon' => [42_195.0, '2026-11-14', '2026-11-02'],
    'marathon-class boundary' => [30_000.0, '2026-11-14', '2026-11-02'],
    'half marathon' => [21_097.0, '2026-11-07', null],
    'just over 15 km' => [15_001.0, '2026-11-07', null],
    '15 km' => [15_000.0, '2026-11-03', null],
    '10K' => [10_000.0, '2026-11-03', null],
]);

it('excludes quality only after race day and through the last day of the window', function (): void {
    $recovery = PostRaceRecovery::after(Carbon::parse('2026-10-31'), 10_000.0);

    expect($recovery->excludesQualityOn('2026-10-31'))->toBeFalse()
        ->and($recovery->excludesQualityOn('2026-11-01'))->toBeTrue()
        ->and($recovery->excludesQualityOn('2026-11-03'))->toBeTrue()
        ->and($recovery->excludesQualityOn('2026-11-04'))->toBeFalse();
});

it('deloads the first full week after race day, even for a race on a Sunday', function (): void {
    $sunday = PostRaceRecovery::after(Carbon::parse('2026-11-01'), 42_195.0);

    expect($sunday->deloadsWeek(Carbon::parse('2026-11-02')))->toBeTrue()
        ->and($sunday->deloadsWeek(Carbon::parse('2026-10-26')))->toBeFalse()
        ->and(PostRaceRecovery::after(Carbon::parse('2026-11-01'), 10_000.0)->deloadsWeek(Carbon::parse('2026-11-02')))->toBeFalse();
});
