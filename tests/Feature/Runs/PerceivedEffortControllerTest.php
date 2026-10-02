<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-06-10 18:00:00'));
});

/** @return array{0: Activity, 1: ActivityDetail} */
function perceivedEffortRun(User $user, array $attributes = []): array
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-06-10 06:00:00'),
        'moving_time' => 2400,
        'elapsed_time' => 2500,
        'distance' => 6000,
        'has_heartrate' => false,
        'average_heartrate' => null,
        'max_heartrate' => null,
        'trimp_edwards' => null,
        'stream_summary' => null,
        ...$attributes,
    ]);

    return [$activity, $detail];
}

it('stores a score with its entry time on a run without heart rate, leaving the run unscored for load', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = perceivedEffortRun($user);

    $this->actingAs($user)
        ->from("/activities/{$activity->id}")
        ->patch(route('activities.effort.update', $activity), ['score' => 6])
        ->assertRedirect("/activities/{$activity->id}");

    $detail->refresh();
    expect($detail->perceived_effort)->toBe(6)
        ->and($detail->perceived_effort_at?->toDateTimeString())->toBe('2026-06-10 18:00:00')
        ->and($detail->trimp_edwards)->toBeNull()
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('scores a run that carries heart rate without touching its TRIMP', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = perceivedEffortRun($user, ['has_heartrate' => true, 'average_heartrate' => 150.0, 'trimp_edwards' => 70.0]);

    $this->actingAs($user)->patch(route('activities.effort.update', $activity), ['score' => 9])->assertSessionHasNoErrors();

    expect($detail->fresh()->perceived_effort)->toBe(9)
        ->and($detail->fresh()->trimp_edwards)->toBe(70.0);
});

it('scores, changes and clears a run of any age', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = perceivedEffortRun($user, ['start_date_local' => Carbon::parse('2026-03-01 06:00:00')]);

    $this->actingAs($user)->patch(route('activities.effort.update', $activity), ['score' => 4])->assertSessionHasNoErrors();
    expect($detail->fresh()->perceived_effort)->toBe(4);

    $this->travel(2)->days();
    $this->actingAs($user)->patch(route('activities.effort.update', $activity), ['score' => 7])->assertSessionHasNoErrors();
    expect($detail->fresh()->perceived_effort)->toBe(7)
        ->and($detail->fresh()->perceived_effort_at?->toDateTimeString())->toBe('2026-06-12 18:00:00');

    $this->actingAs($user)->delete(route('activities.effort.destroy', $activity))->assertSessionHasNoErrors();
    expect($detail->fresh()->perceived_effort)->toBeNull()
        ->and($detail->fresh()->perceived_effort_at)->toBeNull();
});

it('validates the score as a whole number from 1 to 10', function (mixed $score): void {
    $user = User::factory()->create();
    [$activity, $detail] = perceivedEffortRun($user);

    $this->actingAs($user)->patch(route('activities.effort.update', $activity), ['score' => $score])->assertSessionHasErrors('score');

    expect($detail->fresh()->perceived_effort)->toBeNull()
        ->and($detail->fresh()->perceived_effort_at)->toBeNull();
})->with([0, 11, 5.5, 'hard', null]);

it('lets an athlete score only their own run', function (): void {
    $owner = User::factory()->create();
    [$activity, $detail] = perceivedEffortRun($owner);

    $this->patch(route('activities.effort.update', $activity), ['score' => 6])->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->patch(route('activities.effort.update', $activity), ['score' => 6])->assertNotFound();
    $this->actingAs(User::factory()->create())->delete(route('activities.effort.destroy', $activity))->assertNotFound();

    expect($detail->fresh()->perceived_effort)->toBeNull();
});

it('keeps a demo score local, with no narration requested', function (): void {
    $user = User::factory()->demo()->create();
    [$activity, $detail] = perceivedEffortRun($user);

    $this->actingAs($user)->patch(route('activities.effort.update', $activity), ['score' => 4])->assertRedirect();

    expect($detail->fresh()->perceived_effort)->toBe(4)
        ->and(Analysis::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
