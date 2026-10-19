<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\RaceAmbitionState;
use App\Models\Activity;
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

beforeEach(fn () => Carbon::setTestNow('2026-10-05 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

/** @return array{user: User, race: RaceGoal} */
function capacityAthlete(): array
{
    $user = User::factory()->create();
    foreach (range(1, 6) as $weeksAgo) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($weeksAgo)->endOfWeek(Carbon::SUNDAY)->toDateString(),
            'runs' => 4,
            'distance_km' => 30.0,
        ]);
    }
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 4]);
    PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'activity_id' => Activity::factory()->for($user)->create()->id, 'kind' => 'test', 'distance_m' => 10_000, 'elapsed_time_sec' => 4200,
        'performed_on' => Carbon::today()->subWeek(), 'confirmed_at' => now(),
    ]);

    return [
        'user' => $user,
        'race' => RaceGoal::factory()->for($user)->create([
            'distance_m' => 10_000, 'goal_time_sec' => 3000, 'race_date' => Carbon::today()->addWeeks(3)->next(Carbon::SATURDAY)->toDateString(),
        ]),
    ];
}

/** @return list<array{string, string, float, int|null, int|null}> */
function planFingerprint(User $user): array
{
    return array_values(PlannedSession::query()->where('user_id', $user->id)->orderBy('date')->get()
        ->map(fn (PlannedSession $s): array => [
            $s->date->toDateString(), $s->session_type->value, (float) $s->volume_multiplier, $s->prescribed_hard_minutes, $s->prescribed_pace_sec_per_km,
        ])->all());
}

it('plans a 50 minute 10K ambition from 70 minute capacity without a crash build, its goal-pace work at the stepping stone', function (): void {
    ['user' => $user, 'race' => $race] = capacityAthlete();
    $sessionDays = fn (): array => array_map(static fn (array $day): array => array_slice($day, 0, 3), planFingerprint($user));
    $goalPaceDays = fn (): int => PlannedSession::query()->where('user_id', $user->id)->whereNotNull('prescription_race_context->band')->count();
    app(Periodizer::class)->regenerate($user, Carbon::today());
    $ambitious = $sessionDays();
    $ambitiousGoalPaceDays = $goalPaceDays();
    $steppingStonePaces = PlannedSession::query()->where('user_id', $user->id)->whereNotNull('prescription_race_context->band')->where('prescribed_hard_minutes', '>', 0)->get()
        ->map(fn (PlannedSession $s): array => [data_get($s->prescription_race_context, 'band'), $s->prescribed_pace_sec_per_km])->unique()->values()->all();
    $steppingStone = app(RaceAmbitionAssessor::class)->assess($user, $race)->steppingStonePaceSecPerKm;
    $adaptation = PlanAdaptation::query()->where('user_id', $user->id)->firstOrFail();

    $supported = app(RaceAmbitionAssessor::class)->assess($user, $race)->supportedTimeSec;
    $race->update(['goal_time_sec' => $supported]);
    app(Periodizer::class)->regenerate($user, Carbon::today());

    expect($adaptation->reason)->not->toBe(AdaptationReason::BehindRacePace)
        ->and($adaptation->quality_delta)->toBe(0)
        ->and($ambitiousGoalPaceDays)->toBeGreaterThan(0)
        ->and($steppingStonePaces)->toBe([['unsupported', $steppingStone]])
        ->and($sessionDays())->toBe($ambitious)
        ->and($goalPaceDays())->toBeGreaterThan(0);
});

it('prescribes the supported pace on race day and keeps the stated target visible', function (): void {
    ['user' => $user, 'race' => $race] = capacityAthlete();
    app(Periodizer::class)->regenerate($user, Carbon::today());

    $raceDay = collect(app(PlanPageAssembler::class)->weeks($user, Carbon::today()))
        ->flatMap(fn (array $week): array => arrayOf($week['days']))
        ->firstWhere('date', $race->race_date->toDateString());
    $ambition = app(RaceAmbitionAssessor::class)->assess($user, $race);

    expect($ambition->state)->toBe(RaceAmbitionState::Unsupported)
        ->and($ambition->targetPaceSecPerKm)->toBe(300)
        ->and(data_get($raceDay, 'segments.0.pace_sec_per_km'))->toBe($ambition->supportedPaceSecPerKm)
        ->and($race->refresh()->goal_time_sec)->toBe(3000);
});
