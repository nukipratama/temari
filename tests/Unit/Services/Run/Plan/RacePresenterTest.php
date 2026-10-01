<?php

declare(strict_types=1);

use App\Enums\RaceIntent;
use App\Models\PerformanceEvidence;
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
