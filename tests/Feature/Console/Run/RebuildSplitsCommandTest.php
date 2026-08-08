<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\PersonalRecord;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Run\Metrics\WeeklyAggregator;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Sleep::fake();
});

function rebuildSplitsUser(): User
{
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create([
        'access_token' => 'tok',
        'token_expires_at' => Carbon::now()->addHours(2),
    ]);

    return $user;
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

it('fetches laps only for the activities that do not have them yet', function (): void {
    $user = rebuildSplitsUser();
    $missing = rebuildSplitsRun($user, 111);
    $alreadyFetched = rebuildSplitsRun($user, 222, ['laps' => [['lap_index' => 1, 'distance' => 1000.0]]]);

    Http::fake([
        'strava.com/api/v3/activities/111' => Http::response([
            'laps' => [['lap_index' => 1, 'distance' => 1000.0, 'elapsed_time' => 400]],
        ]),
    ]);

    $this->artisan('run:rebuild-splits')
        ->expectsOutputToContain('Pass 1: fetched laps for 1 activity(ies)')
        ->assertSuccessful();

    Http::assertSentCount(1);
    expect($missing->detail->fresh()->laps()[0]['elapsed_time'])->toBe(400)
        ->and($alreadyFetched->detail->fresh()->laps()[0])->not->toHaveKey('elapsed_time');
});

it('stores an empty lap list when Strava reports none, so a re-run does not re-fetch it', function (): void {
    $user = rebuildSplitsUser();
    $activity = rebuildSplitsRun($user, 111);

    Http::fake(['strava.com/api/v3/activities/111' => Http::response(['name' => 'Morning Run'])]);

    $this->artisan('run:rebuild-splits')->assertSuccessful();
    expect($activity->detail->fresh()->laps)->toBe([]);

    $this->artisan('run:rebuild-splits')
        ->expectsOutputToContain('Pass 1: fetched laps for 0 activity(ies)')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('throttles between Strava calls so the shared 200/15min bucket survives a full history', function (): void {
    $user = rebuildSplitsUser();
    rebuildSplitsRun($user, 111);
    rebuildSplitsRun($user, 222);

    Http::fake(['strava.com/api/v3/activities/*' => Http::response(['laps' => []])]);

    $this->artisan('run:rebuild-splits', ['--sleep' => 5])->assertSuccessful();

    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalSeconds === 5.0, 2);
});

it('makes no Strava calls at all with --skip-fetch', function (): void {
    $user = rebuildSplitsUser();
    rebuildSplitsRun($user, 111);

    Http::fake();

    $this->artisan('run:rebuild-splits', ['--skip-fetch' => true])
        ->expectsOutputToContain('Pass 1 skipped')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('keeps going past an activity Strava will not return, and still recomputes the rest', function (): void {
    $user = rebuildSplitsUser();
    $broken = rebuildSplitsRun($user, 111);
    $healthy = rebuildSplitsRun($user, 222);

    Http::fake([
        'strava.com/api/v3/activities/111' => Http::response(['error' => 'Record Not Found'], 404),
        'strava.com/api/v3/activities/222' => Http::response(['laps' => [['lap_index' => 1]]]),
    ]);

    $this->artisan('run:rebuild-splits')
        ->expectsOutputToContain('Pass 1: fetched laps for 1 activity(ies), skipped 1')
        ->expectsOutputToContain('Pass 3: rebuilt records and weekly snapshots for 1 user(s)')
        ->assertSuccessful();

    // The failed one keeps a null laps column and is retried next run; it must
    // not block the other activity's fetch or the later passes.
    expect($broken->detail->fresh()->laps)->toBeNull()
        ->and($healthy->detail->fresh()->laps)->toBe([['lap_index' => 1]]);
});

it('ends the fetch pass gracefully when the Strava bucket is exhausted, then still rebuilds', function (): void {
    $user = rebuildSplitsUser();
    rebuildSplitsRun($user, 111);
    rebuildSplitsRun($user, 222);

    Http::fake(['strava.com/api/v3/activities/*' => Http::response([], 429)]);

    $this->artisan('run:rebuild-splits')
        ->expectsOutputToContain('Pass 1 stopped early')
        ->expectsOutputToContain('Pass 3: rebuilt records and weekly snapshots for 1 user(s)')
        ->assertSuccessful();

    // Stopped at the first 429 rather than burning the rest of the list on it.
    Http::assertSentCount(1);
});

it('stops the fetch pass on a revoked connection rather than replaying the 401 down the whole history', function (): void {
    $user = rebuildSplitsUser();
    rebuildSplitsRun($user, 111);
    rebuildSplitsRun($user, 222);

    Http::fake(['strava.com/api/v3/activities/*' => Http::response([], 401)]);

    $this->artisan('run:rebuild-splits')
        ->expectsOutputToContain('Pass 1 stopped early')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('resets a stale crown instead of re-detecting over it', function (): void {
    $user = rebuildSplitsUser();
    // No stored streams, so pass 2 leaves this summary alone and the test asserts
    // pass 3's reset semantics on a known set of splits.
    $activity = rebuildSplitsRun($user, 111, ['stream_summary' => rebuildSplitsEvenSummary()]);

    // What the pre-backfill rules left behind: a 1 km crown nobody can now beat,
    // because every recomputed split is slower or equal.
    PersonalRecord::factory()->for($user)->create([
        'category' => '1km',
        'value_sec' => 300.0,
        'activity_id' => $activity->id,
    ]);

    $this->artisan('run:rebuild-splits', ['--skip-fetch' => true])->assertSuccessful();

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
    // The whole reason pass 2 opts out of recomputeSummary's forward rebuild:
    // per-activity it is O(weeks-forward), so quadratic over a full history.
    $aggregator->shouldNotReceive('rebuildForwardFrom');
    $this->app->instance(WeeklyAggregator::class, $aggregator);

    $this->artisan('run:rebuild-splits', ['--skip-fetch' => true])
        ->expectsOutputToContain('Pass 2: recomputed 3 stream summary(ies)')
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

    Http::fake(['strava.com/api/v3/activities/*' => Http::response(['laps' => []])]);

    $this->artisan('run:rebuild-splits', ['--user' => $mine->id])
        ->expectsOutputToContain('Pass 3: rebuilt records and weekly snapshots for 1 user(s)')
        ->assertSuccessful();

    Http::assertSentCount(1);
    expect((float) $stale->fresh()->value_sec)->toBe(300.0);
});
