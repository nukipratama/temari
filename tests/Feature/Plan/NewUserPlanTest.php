<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzePlanDayVoiceJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/** A user who has just come back from Strava: history imported, not yet onboarded. */
function connectedRunner(): User
{
    $user = User::factory()->create(['onboarded_at' => null]);

    foreach (range(1, 12) as $i) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::today()->subDays($i * 2),
            'distance' => 7_000,
            'moving_time' => 2_400,
            'trimp_edwards' => 60.0,
        ]);
    }

    return $user;
}

/**
 * `plan:regenerate` only runs on Mondays, and nothing else built a first plan
 * — so a user who signed up on a Tuesday met an empty Plan tab and Home's
 * "No plan yet." card until the following week.
 */
it('has a plan for the week it is in as soon as onboarding is finished', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00'); // a Tuesday
    $user = connectedRunner();

    $this->actingAs($user)->post('/onboarding', [
        'experience_level' => 'experienced',
        'sessions_per_week' => 4,
        'goal_type' => 'consistent',
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

    $thisWeek = PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereBetween('date', [
            Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(),
            Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        ])
        ->count();

    expect($thisWeek)->toBeGreaterThan(0);

    Carbon::setTestNow();
});

it('shows a real week plan on Home straight after onboarding', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00'); // a Tuesday
    $user = connectedRunner();

    $this->actingAs($user)->post('/onboarding', [
        'experience_level' => 'experienced',
        'sessions_per_week' => 4,
        'goal_type' => 'consistent',
    ]);

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('weekPlan', fn (mixed $plan): bool => $plan !== null));

    Carbon::setTestNow();
});

/** The preferences stated on the form are the ones the first week is built from. */
it('builds the first week on the run days the athlete just chose', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00'); // a Tuesday
    $user = connectedRunner();

    $this->actingAs($user)->post('/onboarding', [
        'experience_level' => 'experienced',
        'sessions_per_week' => 3,
        'goal_type' => 'consistent',
        'run_days' => [1, 3, 5],
        'long_run_day' => 5,
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

    $trainingDows = PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereBetween('date', [
            Carbon::today()->startOfWeek(Carbon::MONDAY)->addWeek()->toDateString(),
            Carbon::today()->endOfWeek(Carbon::SUNDAY)->addWeek()->toDateString(),
        ])
        ->get()
        ->reject(fn (PlannedSession $s): bool => $s->session_type->value === 'rest')
        ->map(fn (PlannedSession $s): int => (int) $s->date->dayOfWeekIso - 1)
        ->sort()
        ->values()
        ->all();

    expect($trainingDows)->toBe([1, 3, 5]);

    Carbon::setTestNow();
});

/**
 * The first week is described once, by whichever of the two racers finishes
 * second — onboarding when the backfill has already landed, `KickoffRecapsJob`
 * when it has not.
 */
it('narrates the first week when the backfill has already landed', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00'); // a Tuesday
    Bus::fake();
    $user = connectedRunner();
    $user->markBackfilled();

    $this->actingAs($user)->post('/onboarding', [
        'experience_level' => 'experienced',
        'sessions_per_week' => 4,
        'goal_type' => 'consistent',
    ])->assertSessionHasNoErrors();

    Bus::assertDispatched(AnalyzePlanDayVoiceJob::class);

    Carbon::setTestNow();
});

/** A skeleton over a job nobody queued is false hope, so the day is omitted instead. */
it('narrates nothing, and promises nothing, while the backfill is still running', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00'); // a Tuesday
    Bus::fake();
    $user = connectedRunner();

    $this->actingAs($user)->post('/onboarding', [
        'experience_level' => 'experienced',
        'sessions_per_week' => 4,
        'goal_type' => 'consistent',
    ])->assertSessionHasNoErrors();

    Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);

    // No row means no payload, which is what keeps the Plan page from drawing
    // a skeleton over a job nobody queued.
    expect(Analysis::query()->where('subject_id', $user->id)->exists())->toBeFalse();

    Carbon::setTestNow();
});
