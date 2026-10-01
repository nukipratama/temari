<?php

declare(strict_types=1);

use App\Enums\RaceIntent;
use App\Enums\RaceOutcome;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PerformanceEvidence;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Plan\RaceGoalService;
use App\Services\Run\Plan\RacePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 08:00:00');
    $this->user = User::factory()->create();
    $this->races = app(RaceGoalService::class);
    $this->presenter = app(RacePresenter::class);
});
afterEach(fn () => Carbon::setTestNow());

it('presents the requested target beside the supported effort and the event history', function (): void {
    PerformanceEvidence::query()->create([
        'user_id' => $this->user->id, 'kind' => 'test', 'distance_m' => 10_000, 'elapsed_time_sec' => 4200,
        'performed_on' => Carbon::today()->subWeek(), 'confirmed_at' => now(),
    ]);
    $race = $this->races->submit($this->user, ['race_date' => Carbon::today()->addWeeks(4)->toDateString(), 'distance_m' => 10_000, 'goal_time_sec' => 3000, 'name' => null], RaceIntent::Update);
    $race = $this->races->submit($this->user, ['race_date' => Carbon::today()->addWeeks(5)->toDateString(), 'distance_m' => 10_000, 'goal_time_sec' => 3000, 'name' => null], RaceIntent::Update);

    $payload = $this->presenter->present($this->user, $race->fresh());

    expect($payload['goal_time_sec'])->toBe(3000)
        ->and($payload['ambition']['state'])->toBe('unsupported')
        ->and($payload['ambition']['target_time_sec'])->toBe(3000)
        ->and($payload['ambition']['supported_time_sec'])->toBeGreaterThan(4000)
        ->and($payload['support'])->toBe(['mode' => 'road', 'dedicated_preparation' => true, 'limitation' => null])
        ->and(array_column($payload['history'], 'kind'))->toBe(['created', 'postponed']);
});

it('states the honest limit for a race beyond the marathon', function (): void {
    $race = $this->races->submit($this->user, ['race_date' => Carbon::today()->addWeeks(12)->toDateString(), 'distance_m' => 80_000, 'goal_time_sec' => 36_000, 'name' => null], RaceIntent::Update);

    $payload = $this->presenter->present($this->user, $race);

    expect($payload['support']['mode'])->toBe('general_maintenance')
        ->and($payload['support']['dedicated_preparation'])->toBeFalse()
        ->and($payload['support']['limitation'])->toContain('ultra')
        ->and($payload['ambition']['state'])->toBe('unknown');
});

it('presents a passed race with its outcome and no suggestion once confirmed', function (): void {
    $race = RaceGoal::factory()->for($this->user)->completed()->create([
        'race_date' => '2026-10-04', 'outcome' => RaceOutcome::Confirmed, 'finish_time_sec' => 2_950, 'outcome_recorded_at' => '2026-10-05 08:00:00',
    ]);

    $payload = $this->presenter->presentPast($race);

    expect($payload['outcome'])->toMatchArray(['state' => 'confirmed', 'finish_time_sec' => 2_950, 'suggestion' => null])
        ->and($payload['outcome']['recorded_at'])->not->toBeNull()
        ->and($payload['race_date'])->toBe('2026-10-04');
});

it('reads a pending race as pending and offers the matched run', function (): void {
    $race = RaceGoal::factory()->for($this->user)->completed()->create(['race_date' => '2026-10-04', 'distance_m' => 10_000, 'outcome' => RaceOutcome::Pending]);
    $activity = Activity::factory()->for($this->user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => '2026-10-04 07:00:00', 'distance' => 10_000.0, 'elapsed_time' => 3_000]);

    $payload = $this->presenter->presentPast($race);

    expect($payload['outcome']['state'])->toBe('pending')
        ->and($payload['outcome']['suggestion']['activity_id'])->toBe($activity->id);
});
