<?php

declare(strict_types=1);

use App\Console\Commands\AI\NarrationEvalFixtures;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function evalRun(User $user, Carbon $on, int $elapsedSec, bool $heartRate = true): ActivityDetail
{
    $activity = Activity::factory()->for($user)->create();

    return ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $on,
        'distance' => 8000.0,
        'moving_time' => $elapsedSec,
        'elapsed_time' => $elapsedSec,
        'has_heartrate' => $heartRate,
        'average_heartrate' => $heartRate ? 150.0 : null,
    ]);
}

it('offers the four kinds', function (): void {
    expect(NarrationEvalFixtures::KINDS)->toBe(['plan_day_voice', 'briefing_mascot_voice', 'run_insight', 'profile_voice']);
});

it('covers every plan day trap with a direction answer', function (): void {
    $demo = User::factory()->demo()->create();

    $fixtures = app(NarrationEvalFixtures::class)->for($demo, ['plan_day_voice']);

    expect(array_map(fn ($fixture): string => $fixture->name, $fixtures))->toBe([
        'hit_easy', 'too_hard_easy', 'capped_day', 'eased_original', 'strong_concern', 'excessive', 'unplanned_hard',
    ]);

    DB::beginTransaction();
    $case = $fixtures[1]->build->__invoke();
    DB::rollBack();

    expect($case['evidence']['get_day_plan']['intent'])->toContain('harder than the easy effort')
        ->and($case['direction']['forbidden'])->not->toBe([])
        ->and($case['plain_text'])->toBeTrue();
});

it('words the strong concern and excessive readings from the persisted evidence', function (): void {
    $demo = User::factory()->demo()->create();
    $fixtures = collect(app(NarrationEvalFixtures::class)->for($demo, ['plan_day_voice']))->keyBy('name');

    DB::beginTransaction();
    $strong = $fixtures['strong_concern']->build->__invoke();
    $excessive = $fixtures['excessive']->build->__invoke();
    DB::rollBack();

    expect($strong['evidence']['get_day_plan']['intent'])->toContain('against advice to rest')
        ->and($excessive['evidence']['get_day_plan']['intent'])->toContain('well past its target effort');
});

it('picks the runs furthest from their baseline for the run insight', function (): void {
    $demo = User::factory()->demo()->create();
    foreach ([20, 18, 16, 14, 12, 10] as $daysAgo) {
        evalRun($demo, Carbon::now()->subDays($daysAgo), 2880);
    }
    evalRun($demo, Carbon::now()->subDays(5), 2400);
    evalRun($demo, Carbon::now()->subDays(3), 3800);

    $fixtures = collect(app(NarrationEvalFixtures::class)->for($demo, ['run_insight']))->keyBy('name');

    expect($fixtures->keys()->all())->toBe(['faster_than_past_you', 'slower_than_past_you', 'no_heart_rate']);

    DB::beginTransaction();
    $faster = $fixtures['faster_than_past_you']->build->__invoke();
    $slower = $fixtures['slower_than_past_you']->build->__invoke();
    $noHr = $fixtures['no_heart_rate']->build->__invoke();
    DB::rollBack();

    expect($faster['direction']['required'])->not->toBe([])
        ->and($slower['direction']['required'])->not->toBe([])
        ->and($faster['direction'])->not->toBe($slower['direction'])
        ->and($noHr['direction']['forbidden'])->not->toBe([]);
});

it('skips a run insight fixture the history cannot support', function (): void {
    $demo = User::factory()->demo()->create();

    $fixtures = collect(app(NarrationEvalFixtures::class)->for($demo, ['run_insight']))->keyBy('name');

    DB::beginTransaction();
    $case = $fixtures['faster_than_past_you']->build->__invoke();
    DB::rollBack();

    expect($case)->toBeNull();
});

it('strips heart rate from the no heart rate run inside the transaction only', function (): void {
    $demo = User::factory()->demo()->create();
    $run = evalRun($demo, Carbon::now()->subDay(), 2880);
    $fixtures = collect(app(NarrationEvalFixtures::class)->for($demo, ['run_insight']))->keyBy('name');

    DB::beginTransaction();
    $fixtures['no_heart_rate']->build->__invoke();
    $during = $run->fresh()->has_heartrate;
    DB::rollBack();

    expect($during)->toBeFalse()
        ->and($run->fresh()->has_heartrate)->toBeTrue();
});

it('builds the post run briefing against a run logged today', function (): void {
    $demo = User::factory()->demo()->create();
    $fixtures = collect(app(NarrationEvalFixtures::class)->for($demo, ['briefing_mascot_voice']))->keyBy('name');

    expect($fixtures->keys()->all())->toBe(['hit_easy_today', 'too_hard_easy_today']);

    DB::beginTransaction();
    $case = $fixtures['too_hard_easy_today']->build->__invoke();
    $ranToday = $case['evidence']['context']['ran_today'];
    DB::rollBack();

    expect($ranToday)->toBeTrue();
});

it('derives the load direction from the evidence the model reads', function (): void {
    expect(NarrationEvalFixtures::loadDirection(['get_training_load' => ['training_load' => ['load_balance' => 'heavy']]])['forbidden'])
        ->toContain('\\bfresh\\b')
        ->and(NarrationEvalFixtures::loadDirection(['get_training_load' => ['training_load' => ['load_balance' => 'fresh']]])['forbidden'])
        ->toContain('\\bheavy\\b')
        ->and(NarrationEvalFixtures::loadDirection([])['forbidden'])->toBe([NarrationEvalFixtures::RETIRED_LOAD_NAMES]);
});

it('builds the profile voice with the progression direction', function (): void {
    $demo = User::factory()->demo()->create();
    $fixtures = app(NarrationEvalFixtures::class)->for($demo, ['profile_voice']);

    expect($fixtures)->toHaveCount(1)
        ->and($fixtures[0]->name)->toBe('progression_direction');
});
