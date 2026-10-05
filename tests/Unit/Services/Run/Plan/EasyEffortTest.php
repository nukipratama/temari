<?php

declare(strict_types=1);

use App\Models\ActivityDetail;
use App\Services\Run\Plan\EasyEffort;

/** @param  array<string, mixed>  $summary */
function easyEffortRun(int $movingSec, array $summary): ActivityDetail
{
    return new ActivityDetail()->forceFill(['moving_time' => $movingSec, 'elapsed_time' => $movingSec, 'stream_summary' => $summary]);
}

it('has no reading when no run carries heart rate', function (): void {
    expect(EasyEffort::of([]))->toBeNull()
        ->and(EasyEffort::of([easyEffortRun(3600, []), easyEffortRun(1800, ['over_easy_cap_sec' => 900])]))->toBeNull();
});

it('sums the day across the runs that carry heart rate', function (): void {
    $effort = EasyEffort::of([
        easyEffortRun(3000, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 600]),
        easyEffortRun(2400, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 360]),
        easyEffortRun(1800, []),
    ]);

    expect($effort?->capBpm)->toBe(150)
        ->and($effort?->overCapSec)->toBe(960)
        ->and($effort?->movingSec)->toBe(5400)
        ->and($effort?->overCapMinutes())->toBe(16.0)
        ->and($effort?->limitMinutes())->toBe(15.0)
        ->and($effort?->tooHard())->toBeTrue()
        ->and($effort?->egregious())->toBeFalse();
});

it('takes the time a session asked to be run over the cap off first, never below zero', function (): void {
    $run = easyEffortRun(6000, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 2400]);

    expect(EasyEffort::of([$run], 1800.0)?->overCapSec)->toBe(600)
        ->and(EasyEffort::of([$run], 3600.0)?->overCapSec)->toBe(0);
});

it('draws the too-hard line at fifteen minutes, or a fifth of a run under 75 minutes', function (int $movingSec, int $overSec, bool $tooHard): void {
    expect(EasyEffort::of([easyEffortRun($movingSec, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => $overSec])])?->tooHard())->toBe($tooHard);
})->with([
    '2 h at 15 min' => [7200, 900, false],
    '2 h past 15 min' => [7200, 901, true],
    '75 min at 15 min' => [4500, 900, false],
    '74 min past a fifth' => [4440, 889, true],
    '30 min at a fifth' => [1800, 360, false],
    '30 min past a fifth' => [1800, 361, true],
]);

it('draws the egregious line at thirty minutes, or two fifths of a run under 75 minutes', function (int $movingSec, int $overSec, bool $egregious): void {
    expect(EasyEffort::of([easyEffortRun($movingSec, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => $overSec])])?->egregious())->toBe($egregious);
})->with([
    '2 h at 30 min' => [7200, 1800, false],
    '2 h past 30 min' => [7200, 1801, true],
    '30 min at two fifths' => [1800, 720, false],
    '30 min past two fifths' => [1800, 721, true],
]);
