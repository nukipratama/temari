<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\Metrics\WeeklyAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function rebuildSplitsUser(): User
{
    return User::factory()->create();
}

/**
 * @param  array<string, mixed>  $detailAttributes
 */
function rebuildSplitsRun(User $user, int $externalId, array $detailAttributes = []): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create([
        'strava_external_id' => $externalId,
    ]);
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-05-10 06:30:00'),
        'distance' => 5000.0,
        ...$detailAttributes,
    ]);

    return $activity;
}

/**
 * Five even kilometres at 400 s each: slow enough that any stale record standing
 * over it could only have come from the pre-backfill rules.
 *
 * @return array<string, mixed>
 */
function rebuildSplitsEvenSummary(): array
{
    return [
        'per_km' => array_map(
            fn (int $km): array => ['km' => $km, 'distance_m' => 1000.0, 'elapsed_sec' => 400.0],
            range(1, 5),
        ),
    ];
}

it('resets a stale crown instead of re-detecting over it', function (): void {
    $user = rebuildSplitsUser();
    // No stored streams, so pass 1 leaves this summary alone and the test asserts
    // pass 2's reset semantics on a known set of splits.
    $activity = rebuildSplitsRun($user, 111, ['stream_summary' => rebuildSplitsEvenSummary()]);

    // What the pre-backfill rules left behind: a 1 km crown nobody can now beat,
    // because every recomputed split is slower or equal.
    PersonalRecord::factory()->for($user)->create([
        'category' => '1km',
        'value_sec' => 300.0,
        'activity_id' => $activity->id,
    ]);

    $this->artisan('run:rebuild-splits')->assertSuccessful();

    $record = PersonalRecord::query()->where('user_id', $user->id)->where('category', '1km')->first();
    expect($record)->not->toBeNull()
        ->and((float) $record->value_sec)->toBe(400.0);
});

it('rebuilds weekly snapshots once per user, not once per activity', function (): void {
    $user = rebuildSplitsUser();
    foreach ([111, 222, 333] as $externalId) {
        $activity = rebuildSplitsRun($user, $externalId);
        ActivityStream::factory()->for($activity)->create();
    }

    $aggregator = Mockery::mock(WeeklyAggregator::class);
    $aggregator->shouldReceive('rebuildFor')->once()->andReturn(1);
    // The whole reason pass 1 opts out of recomputeSummary's forward rebuild:
    // per-activity it is O(weeks-forward), so quadratic over a full history.
    $aggregator->shouldNotReceive('rebuildForwardFrom');
    $this->app->instance(WeeklyAggregator::class, $aggregator);

    $this->artisan('run:rebuild-splits')
        ->expectsOutputToContain('Pass 1: recomputed 3 stream summary(ies)')
        ->assertSuccessful();
});

it('leaves another user untouched when scoped with --user', function (): void {
    $mine = rebuildSplitsUser();
    $theirs = rebuildSplitsUser();
    rebuildSplitsRun($mine, 111, ['stream_summary' => rebuildSplitsEvenSummary()]);
    rebuildSplitsRun($theirs, 222, ['stream_summary' => rebuildSplitsEvenSummary()]);

    $stale = PersonalRecord::factory()->for($theirs)->create([
        'category' => '1km',
        'value_sec' => 300.0,
    ]);

    $this->artisan('run:rebuild-splits', ['--user' => $mine->id])
        ->expectsOutputToContain('Pass 2: rebuilt records and weekly snapshots for 1 user(s)')
        ->assertSuccessful();

    expect((float) $stale->fresh()->value_sec)->toBe(300.0);
});
