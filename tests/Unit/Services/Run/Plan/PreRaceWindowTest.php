<?php

declare(strict_types=1);

use App\Models\RaceGoal;
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

it('locks the override in race week only for a race up to 10 km, and two weeks before it for anything longer', function (int $distanceM, string $weekStart, bool $locked): void {
    $race = new RaceGoal()->forceFill(['race_date' => '2026-12-19', 'distance_m' => $distanceM]);

    expect(PreRaceWindow::locksOverride(Carbon::parse($weekStart), $race))->toBe($locked);
})->with([
    '10K race week' => [10000, '2026-12-14', true],
    '10K one week out' => [10000, '2026-12-07', false],
    '5K two weeks out' => [5000, '2026-11-30', false],
    'half race week' => [21097, '2026-12-14', true],
    'half two weeks out' => [21097, '2026-11-30', true],
    'half three weeks out' => [21097, '2026-11-23', false],
    'marathon two weeks out' => [42195, '2026-11-30', true],
]);

it('locks no override without a race', function (): void {
    expect(PreRaceWindow::locksOverride(Carbon::parse('2026-12-14'), null))->toBeFalse();
});
