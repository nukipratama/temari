<?php

declare(strict_types=1);

use App\Services\Run\Plan\PreRaceWindow;
use App\Services\Run\Plan\TimeTrialSchedule;
use Illuminate\Support\Carbon;

it('holds race week and the weeks the trial-free rule keeps clear before it', function (string $weekStart, bool $inside): void {
    expect(PreRaceWindow::contains(Carbon::parse($weekStart), Carbon::parse('2026-12-19')))->toBe($inside);
})->with([
    'race week' => ['2026-12-14', true],
    'one week out' => ['2026-12-07', true],
    'two weeks out' => ['2026-11-30', true],
    'three weeks out' => ['2026-11-23', false],
    'ten weeks out' => ['2026-10-05', false],
]);

it('spans the same weeks as the time-trial exclusion', function (): void {
    $raceWeek = Carbon::parse('2026-12-14');

    expect(PreRaceWindow::contains($raceWeek->copy()->subWeeks(TimeTrialSchedule::TRIAL_FREE_WEEKS_BEFORE_RACE), Carbon::parse('2026-12-19')))->toBeTrue()
        ->and(PreRaceWindow::contains($raceWeek->copy()->subWeeks(TimeTrialSchedule::TRIAL_FREE_WEEKS_BEFORE_RACE + 1), Carbon::parse('2026-12-19')))->toBeFalse();
});

it('holds nothing without a race', function (): void {
    expect(PreRaceWindow::contains(Carbon::parse('2026-12-14'), null))->toBeFalse();
});
