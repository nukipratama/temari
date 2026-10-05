<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\RaceAmbitionState;
use App\Enums\SessionType;
use App\Services\Run\Plan\GoalPaceWork;
use App\Services\Run\Plan\PlanInputs;
use Illuminate\Support\Carbon;

function goalPaceWorkInputs(float $distanceM, ?RaceAmbitionState $band, ?int $goalTimeSec = 3000): PlanInputs
{
    return new PlanInputs(
        userId: 1,
        today: Carbon::parse('2026-11-02'),
        seasonStart: Carbon::parse('2026-08-03'),
        seasonEnd: Carbon::parse('2026-12-19'),
        recovery: null,
        raceDate: Carbon::parse('2026-12-19'),
        raceDistanceM: $distanceM,
        sessionsPerWeek: 4,
        runDays: null,
        longRunDay: null,
        adaptation: ['reason' => AdaptationReason::Steady, 'deload' => false, 'quality_delta' => 0, 'adherence_pct' => 100, 'stimulus_adherence_pct' => 100],
        pinnedDates: [],
        settledDates: [],
        projectedRaceSeconds: null,
        raceGoalTimeSec: $goalTimeSec,
        raceAmbitionState: $band,
    );
}

it('counts the window back from race week, inclusive', function (string $monday, float $distanceM, bool $inside): void {
    expect(GoalPaceWork::inWindow(Carbon::parse($monday), Carbon::parse('2026-12-19'), $distanceM))->toBe($inside);
})->with([
    '10K race week' => ['2026-12-14', 10_000.0, true],
    '10K week 6' => ['2026-11-09', 10_000.0, true],
    '10K week 7' => ['2026-11-02', 10_000.0, false],
    'half week 8' => ['2026-10-26', 21_097.5, true],
    'half week 9' => ['2026-10-19', 21_097.5, false],
    'after race week' => ['2026-12-21', 21_097.5, false],
]);

it('sizes the window and kind by race distance', function (float $distanceM, int $weeks, string $kind): void {
    expect(GoalPaceWork::windowWeeks($distanceM))->toBe($weeks)
        ->and(GoalPaceWork::kindFor($distanceM))->toBe($kind);
})->with([
    [3_000.0, 6, '5k'],
    [5_000.0, 6, '5k'],
    [10_000.0, 6, '10k'],
    [15_000.0, 8, 'half'],
    [21_097.5, 8, 'half'],
    [42_195.0, 8, 'marathon'],
]);

it('exists only for supported bands, race phases and dedicated preparation inside the window', function (float $distanceM, ?RaceAmbitionState $band, PlanPhase $phase, string $monday, ?int $goalTimeSec, bool $exists): void {
    expect(GoalPaceWork::forWeek(goalPaceWorkInputs($distanceM, $band, $goalTimeSec), Carbon::parse($monday), $phase) !== null)->toBe($exists);
})->with([
    'on track' => [10_000.0, RaceAmbitionState::OnTrack, PlanPhase::Peak, '2026-11-16', 3000, true],
    'ambitious' => [10_000.0, RaceAmbitionState::Ambitious, PlanPhase::Taper, '2026-12-14', 3000, true],
    'unsupported' => [10_000.0, RaceAmbitionState::Unsupported, PlanPhase::Peak, '2026-11-16', 3000, false],
    'low evidence' => [10_000.0, RaceAmbitionState::LowEvidence, PlanPhase::Peak, '2026-11-16', 3000, false],
    'unknown' => [10_000.0, RaceAmbitionState::Unknown, PlanPhase::Peak, '2026-11-16', 3000, false],
    'no band' => [10_000.0, null, PlanPhase::Peak, '2026-11-16', 3000, false],
    'deload' => [10_000.0, RaceAmbitionState::OnTrack, PlanPhase::Deload, '2026-11-16', 3000, false],
    'base' => [10_000.0, RaceAmbitionState::OnTrack, PlanPhase::Base, '2026-11-16', 3000, false],
    'outside the window' => [10_000.0, RaceAmbitionState::OnTrack, PlanPhase::Build, '2026-11-02', 3000, false],
    'ultra' => [50_000.0, RaceAmbitionState::OnTrack, PlanPhase::Peak, '2026-11-16', 3000, false],
    'no goal time' => [10_000.0, RaceAmbitionState::OnTrack, PlanPhase::Peak, '2026-11-16', null, false],
]);

it('carries the goal pace, kind and band it was generated with', function (): void {
    $work = GoalPaceWork::forWeek(goalPaceWorkInputs(10_000.0, RaceAmbitionState::Ambitious), Carbon::parse('2026-11-16'), PlanPhase::Peak);

    expect($work?->context())->toBe(['distance_m' => 10_000, 'goal_pace_sec_per_km' => 300, 'kind' => '10k', 'band' => 'ambitious'])
        ->and($work?->paceBand())->toBe(PaceBand::Threshold)
        ->and($work?->isMarathon())->toBeFalse()
        ->and($work?->racesLongAtGoalPace())->toBeFalse();
});

it('picks a pace band per kind and races the long run at goal pace only for an on-track marathon', function (string $kind, RaceAmbitionState $band, PaceBand $paceBand, bool $long): void {
    $work = new GoalPaceWork($kind, 1, 300, $band);

    expect($work->paceBand())->toBe($paceBand)
        ->and($work->racesLongAtGoalPace())->toBe($long);
})->with([
    ['5k', RaceAmbitionState::OnTrack, PaceBand::Interval, false],
    ['half', RaceAmbitionState::OnTrack, PaceBand::Threshold, false],
    ['marathon', RaceAmbitionState::OnTrack, PaceBand::Marathon, true],
    ['marathon', RaceAmbitionState::Ambitious, PaceBand::Marathon, false],
]);

it('replaces the kind\'s own form first, otherwise the week\'s first tempo or interval', function (string $kind, array $types, ?string $replaced): void {
    $rows = [];
    foreach ($types as $date => $type) {
        $rows[$date] = ['session_type' => $type];
    }

    expect(new GoalPaceWork($kind, 1, 300, RaceAmbitionState::OnTrack)->replacedDate($rows))->toBe($replaced);
})->with([
    '10K prefers the interval' => ['10k', ['2026-11-19' => SessionType::Interval, '2026-11-17' => SessionType::Tempo, '2026-11-22' => SessionType::Long], '2026-11-19'],
    '10K falls back to the tempo' => ['10k', ['2026-11-19' => SessionType::Tempo, '2026-11-22' => SessionType::Long], '2026-11-19'],
    'half prefers the tempo' => ['half', ['2026-11-17' => SessionType::Interval, '2026-11-19' => SessionType::Tempo], '2026-11-19'],
    'half takes the first interval' => ['half', ['2026-11-19' => SessionType::Interval, '2026-11-17' => SessionType::Interval], '2026-11-17'],
    'no quality' => ['5k', ['2026-11-22' => SessionType::Long, '2026-11-18' => SessionType::Easy], null],
]);

it('shapes goal-pace work by kind and leaves every other session its own shape', function (SessionType $type, ?array $context, SessionType $shape): void {
    expect(GoalPaceWork::shapeOf($type, $context))->toBe($shape);
})->with([
    'reps on a 10K tempo day' => [SessionType::Tempo, ['kind' => '10k', 'band' => 'on_track'], SessionType::Interval],
    'a block on a half interval day' => [SessionType::Interval, ['kind' => 'half', 'band' => 'ambitious'], SessionType::Tempo],
    'a marathon long run' => [SessionType::Long, ['kind' => 'marathon', 'band' => 'on_track'], SessionType::Long],
    'supported marathon tempo' => [SessionType::Tempo, ['kind' => 'marathon'], SessionType::Tempo],
    'no context' => [SessionType::Interval, null, SessionType::Interval],
]);
