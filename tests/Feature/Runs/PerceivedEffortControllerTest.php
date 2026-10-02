<?php

declare(strict_types=1);

use App\Enums\PlannedSessionStatus;
use App\Jobs\Run\RebuildTrendSnapshotsJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\ComplianceScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-06-10 18:00:00'));
});

/** @return array{0: Activity, 1: ActivityDetail} */
function unscoredRun(User $user, array $attributes = []): array
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

function weekOf(User $user): ?WeeklySnapshot
{
    return WeeklySnapshot::query()->where('user_id', $user->id)->where('week_ending', '2026-06-14')->first();
}

it('scores a run without heart rate as RPE times moving minutes over two, through the weekly snapshot', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = unscoredRun($user);

    $this->actingAs($user)
        ->from("/activities/{$activity->id}")
        ->patch(route('activities.effort.update', $activity), ['score' => 6])
        ->assertRedirect("/activities/{$activity->id}");

    $detail->refresh();
    expect($detail->perceived_effort)->toBe(6)
        ->and($detail->trimp_edwards)->toBe(120.0)
        ->and($detail->distance)->toBe(6000.0)
        ->and(weekOf($user)?->weekly_trimp)->toBe(120.0)
        ->and(weekOf($user)?->distance_km)->toBe(6.0);
});

it('recomputes on a changed score and marks the trend snapshots dirty from the run date', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = unscoredRun($user, ['perceived_effort' => 6, 'trimp_edwards' => 120.0]);

    $this->actingAs($user)->patch(route('activities.effort.update', $activity), ['score' => 3])->assertRedirect();

    expect($detail->fresh()->trimp_edwards)->toBe(60.0)
        ->and(weekOf($user)?->weekly_trimp)->toBe(60.0)
        ->and($user->fresh()->trend_snapshots_pending_from?->toDateString())->toBe('2026-06-10');
    Queue::assertPushed(RebuildTrendSnapshotsJob::class);
});

it('never re-grades a day when a score is saved or changed', function (): void {
    $user = User::factory()->create();
    [$activity] = unscoredRun($user);
    $session = PlannedSession::factory()->for($user)->create([
        'date' => '2026-06-10',
        'prescribed_km' => 6.0,
        'status' => PlannedSessionStatus::Missed,
        'compliance_score' => null,
        'intent_verdict' => null,
    ]);
    $graded = ['status', 'compliance_score', 'distance_score', 'intent_verdict', 'intent_evidence', 'ran_anyway'];
    $before = $session->fresh()->only($graded);

    $this->actingAs($user)->patch(route('activities.effort.update', $activity), ['score' => 8])->assertRedirect();
    $this->actingAs($user)->patch(route('activities.effort.update', $activity), ['score' => 2])->assertRedirect();

    expect($session->fresh()->only($graded))->toBe($before)
        ->and($user->fresh()->plan_reconciliation_pending_from)->toBeNull();

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-06-10'), Carbon::today());
    expect($session->fresh()->status)->not->toBe(PlannedSessionStatus::Missed);
});

it('clears a score back to unscored, not zero', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = unscoredRun($user);
    $this->actingAs($user)->patch(route('activities.effort.update', $activity), ['score' => 5])->assertRedirect();

    $this->actingAs($user)->delete(route('activities.effort.destroy', $activity))->assertRedirect();

    $detail->refresh();
    expect($detail->perceived_effort)->toBeNull()
        ->and($detail->trimp_edwards)->toBeNull()
        ->and(weekOf($user)?->weekly_trimp)->toBeNull()
        ->and(weekOf($user)?->distance_km)->toBe(6.0);
});

it('refuses a new, changed or cleared score once 72 hours have passed since the start', function (): void {
    $user = User::factory()->create();
    [$fresh, $freshDetail] = unscoredRun($user, ['start_date_local' => Carbon::parse('2026-06-07 18:00:00')]);
    [$scored, $scoredDetail] = unscoredRun($user, ['start_date_local' => Carbon::parse('2026-06-07 17:00:00'), 'perceived_effort' => 4, 'trimp_edwards' => 80.0]);

    $this->actingAs($user)->patch(route('activities.effort.update', $fresh), ['score' => 6])->assertSessionHasErrors('score');
    $this->actingAs($user)->patch(route('activities.effort.update', $scored), ['score' => 7])->assertSessionHasErrors('score');
    $this->actingAs($user)->delete(route('activities.effort.destroy', $scored))->assertSessionHasErrors('score');

    expect($freshDetail->fresh()->perceived_effort)->toBeNull()
        ->and($freshDetail->fresh()->trimp_edwards)->toBeNull()
        ->and($scoredDetail->fresh()->perceived_effort)->toBe(4)
        ->and($scoredDetail->fresh()->trimp_edwards)->toBe(80.0);
});

it('refuses a score on a run that carries heart rate', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = unscoredRun($user, ['has_heartrate' => true, 'average_heartrate' => 150.0, 'trimp_edwards' => 70.0]);

    $this->actingAs($user)->patch(route('activities.effort.update', $activity), ['score' => 9])->assertSessionHasErrors('score');

    expect($detail->fresh()->perceived_effort)->toBeNull()
        ->and($detail->fresh()->trimp_edwards)->toBe(70.0);
});

it('validates the score as a whole number from 1 to 10', function (mixed $score): void {
    $user = User::factory()->create();
    [$activity, $detail] = unscoredRun($user);

    $this->actingAs($user)->patch(route('activities.effort.update', $activity), ['score' => $score])->assertSessionHasErrors('score');

    expect($detail->fresh()->perceived_effort)->toBeNull();
})->with([0, 11, 5.5, 'hard', null]);

it('lets an athlete score only their own run', function (): void {
    $owner = User::factory()->create();
    [$activity, $detail] = unscoredRun($owner);

    $this->patch(route('activities.effort.update', $activity), ['score' => 6])->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->patch(route('activities.effort.update', $activity), ['score' => 6])->assertNotFound();
    $this->actingAs(User::factory()->create())->delete(route('activities.effort.destroy', $activity))->assertNotFound();

    expect($detail->fresh()->perceived_effort)->toBeNull();
});

it('keeps a demo score local, with no narration requested', function (): void {
    $user = User::factory()->demo()->create();
    [$activity, $detail] = unscoredRun($user);

    $this->actingAs($user)->patch(route('activities.effort.update', $activity), ['score' => 4])->assertRedirect();

    expect($detail->fresh()->trimp_edwards)->toBe(80.0)
        ->and(Analysis::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
