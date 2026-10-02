<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\RaceAmbitionState;
use App\Models\PerformanceEvidence;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\PlanPageAssembler;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\RaceAmbitionAssessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 08:00:00');
    $this->user = User::factory()->create();
    foreach (range(1, 6) as $weeksAgo) {
        WeeklySnapshot::factory()->for($this->user)->create([
            'week_ending' => Carbon::today()->subWeeks($weeksAgo)->endOfWeek(Carbon::SUNDAY)->toDateString(),
            'runs' => 4,
            'distance_km' => 30.0,
        ]);
    }
    TrainingPreference::factory()->for($this->user)->create(['sessions_per_week' => 4]);
    PerformanceEvidence::query()->create([
        'user_id' => $this->user->id, 'kind' => 'test', 'distance_m' => 10_000, 'elapsed_time_sec' => 4200,
        'performed_on' => Carbon::today()->subWeek(), 'confirmed_at' => now(),
    ]);
    $this->race = RaceGoal::factory()->for($this->user)->create([
        'distance_m' => 10_000, 'goal_time_sec' => 3000, 'race_date' => Carbon::today()->addWeeks(3)->next(Carbon::SATURDAY)->toDateString(),
    ]);
});
afterEach(fn () => Carbon::setTestNow());

function planFingerprint(User $user): array
{
    return PlannedSession::query()->where('user_id', $user->id)->orderBy('date')->get()
        ->map(fn (PlannedSession $s): array => [
            $s->date->toDateString(), $s->session_type->value, (float) $s->volume_multiplier, $s->prescribed_hard_minutes, $s->prescribed_pace_sec_per_km,
        ])->all();
}

it('plans a 50 minute 10K ambition from 70 minute capacity without a crash build', function (): void {
    app(Periodizer::class)->regenerate($this->user, Carbon::today());
    $ambitious = planFingerprint($this->user);
    $adaptation = PlanAdaptation::query()->where('user_id', $this->user->id)->firstOrFail();

    $supported = app(RaceAmbitionAssessor::class)->assess($this->user, $this->race)->supportedTimeSec;
    $this->race->update(['goal_time_sec' => $supported]);
    app(Periodizer::class)->regenerate($this->user, Carbon::today());

    expect($adaptation->reason)->not->toBe(AdaptationReason::BehindRacePace)
        ->and($adaptation->quality_delta)->toBe(0)
        ->and(planFingerprint($this->user))->toBe($ambitious);
});

it('prescribes the supported pace on race day and keeps the stated target visible', function (): void {
    app(Periodizer::class)->regenerate($this->user, Carbon::today());

    $raceDay = collect(app(PlanPageAssembler::class)->weeks($this->user, Carbon::today()))
        ->flatMap(fn (array $week): array => $week['days'])
        ->firstWhere('date', $this->race->race_date->toDateString());
    $ambition = app(RaceAmbitionAssessor::class)->assess($this->user, $this->race);

    expect($ambition->state)->toBe(RaceAmbitionState::Unsupported)
        ->and($ambition->targetPaceSecPerKm)->toBe(300)
        ->and($raceDay['segments'][0]['pace_sec_per_km'])->toBe($ambition->supportedPaceSecPerKm)
        ->and($this->race->fresh()->goal_time_sec)->toBe(3000);
});
