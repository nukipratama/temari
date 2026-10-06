<?php

declare(strict_types=1);

use App\Actions\Geo\ReverseGeocodeAction;
use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\AI\MaintainerAlerter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('queues a resolve job for each unresolved detail with coords', function (): void {
    Queue::fake();

    [$a, $b] = Activity::factory()->count(2)->create();
    ActivityDetail::factory()->for($a)->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_resolved_at' => null,
    ]);
    ActivityDetail::factory()->for($b)->create([
        'start_lat' => -7.95,
        'start_lng' => 112.61,
        'location_resolved_at' => null,
    ]);

    $this->artisan('geo:backfill-locations')->assertSuccessful();

    Queue::assertPushed(ResolveActivityLocationJob::class, 2);
});

it('skips already-resolved details and those without coords', function (): void {
    Queue::fake();

    [$resolved, $missing, $unresolved] = Activity::factory()->count(3)->create();
    ActivityDetail::factory()->for($resolved)->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_resolved_at' => now()->subDay(),
    ]);
    ActivityDetail::factory()->for($missing)->create([
        'start_lat' => null,
        'start_lng' => null,
        'location_resolved_at' => null,
    ]);
    ActivityDetail::factory()->for($unresolved)->create([
        'start_lat' => -7.0,
        'start_lng' => 112.0,
        'location_resolved_at' => null,
    ]);

    $this->artisan('geo:backfill-locations')->assertSuccessful();

    Queue::assertPushed(ResolveActivityLocationJob::class, 1);
});

it('staggers resolve dispatches one second apart', function (): void {
    Queue::fake();
    $this->freezeTime();

    Activity::factory()
        ->count(3)
        ->create()
        ->each(fn ($a) => ActivityDetail::factory()->for($a)->create([
            'start_lat' => -6.0,
            'start_lng' => 106.0,
            'location_resolved_at' => null,
        ]));

    $this->artisan('geo:backfill-locations')->assertSuccessful();

    $delays = Queue::pushed(ResolveActivityLocationJob::class)
        ->map(fn ($job): int => (int) round(now()->diffInSeconds($job->delay)))
        ->sort()
        ->values()
        ->all();

    expect($delays)->toBe([0, 1, 2]);
});

it('honors the --limit option', function (): void {
    Queue::fake();

    Activity::factory()
        ->count(5)
        ->create()
        ->each(fn ($a) => ActivityDetail::factory()->for($a)->create([
            'start_lat' => -6.0,
            'start_lng' => 106.0,
            'location_resolved_at' => null,
        ]));

    $this->artisan('geo:backfill-locations', ['--limit' => 2])->assertSuccessful();

    Queue::assertPushed(ResolveActivityLocationJob::class, 2);
});

it('skips cached failures and no-address results without starving later rows past the limit', function (): void {
    $limit = 5;
    $skippedCount = 2 * ($limit + 1);
    Queue::fake();
    $this->freezeTime();
    Cache::flush();
    $requestCount = 0;
    Http::fake([
        'nominatim.openstreetmap.org/*' => function () use (&$requestCount, $limit) {
            $requestCount++;

            return $requestCount <= $limit + 1
                ? Http::response('rate limited', 429)
                : Http::response(['address' => []]);
        },
    ]);

    $activities = Activity::factory()->count($skippedCount + $limit)->create();
    $resolver = new ReverseGeocodeAction();
    foreach ($activities->take($skippedCount)->values() as $index => $activity) {
        $lat = -6.0 - ($index / 1000);
        ActivityDetail::factory()->for($activity)->create([
            'start_lat' => $lat,
            'start_lng' => 106.0,
            'location_resolved_at' => null,
        ]);
        $resolver($lat, 106.0);
        $this->travel(1)->seconds();
    }

    $eligibleIds = $activities->skip($skippedCount)->map(fn (Activity $activity): int => ActivityDetail::factory()->for($activity)->create([
        'start_lat' => -8.0,
        'start_lng' => 106.0,
        'location_resolved_at' => null,
    ])->id)->values()->all();

    $this->artisan('geo:backfill-locations', ['--limit' => $limit])->assertSuccessful();

    $queuedIds = Queue::pushed(ResolveActivityLocationJob::class)
        ->map(fn (ResolveActivityLocationJob $job): int => $job->activityDetailId)
        ->all();
    expect($queuedIds)->toHaveCount($limit)->toBe($eligibleIds);
    Http::assertSentCount($skippedCount);
});

it('reaches a newer never-attempted row behind 200 attempted ones', function (): void {
    Queue::fake();

    ActivityDetail::factory()->count(200)->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_resolved_at' => null,
        'location_attempts' => 1,
        'location_attempted_at' => now()->subDays(2),
    ]);
    $fresh = ActivityDetail::factory()->create([
        'start_lat' => -7.95,
        'start_lng' => 112.61,
        'location_resolved_at' => null,
    ]);

    $this->artisan('geo:backfill-locations')->assertSuccessful();

    Queue::assertPushed(
        ResolveActivityLocationJob::class,
        fn (ResolveActivityLocationJob $job) => $job->activityDetailId === $fresh->id,
    );
    Queue::assertPushed(ResolveActivityLocationJob::class, 200);
});

it('skips rows that reached five attempts', function (): void {
    Queue::fake();

    ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_resolved_at' => null,
        'location_attempts' => 5,
        'location_attempted_at' => now()->subDays(3),
    ]);

    $this->artisan('geo:backfill-locations')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('skips a row attempted within the last day, so an hourly sweep spends one attempt per day', function (): void {
    Queue::fake();

    ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_resolved_at' => null,
        'location_attempts' => 1,
        'location_attempted_at' => now()->subHours(2),
    ]);

    $this->artisan('geo:backfill-locations')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('backfills start_lat/start_lng from summary_polyline when coords are null', function (): void {
    Queue::fake();
    $detail = ActivityDetail::factory()->create([
        'start_lat' => null,
        'start_lng' => null,
        // Google polyline-encoding canonical example. First point ≈ (38.5, -120.2).
        'summary_polyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
        'location_resolved_at' => null,
    ]);

    $this->artisan('geo:backfill-locations')->assertSuccessful();

    $detail->refresh();
    expect($detail->start_lat)->toEqualWithDelta(38.5, 0.0001);
    expect($detail->start_lng)->toEqualWithDelta(-120.2, 0.0001);
    Queue::assertPushed(ResolveActivityLocationJob::class, 1);
});

it('skips polyline backfill when the polyline is empty/malformed', function (): void {
    Queue::fake();
    $detail = ActivityDetail::factory()->create([
        'start_lat' => null,
        'start_lng' => null,
        'summary_polyline' => '_', // truncated → decoder returns null
    ]);

    $this->artisan('geo:backfill-locations')->assertSuccessful();

    expect($detail->fresh()->start_lat)->toBeNull();
    Queue::assertNothingPushed();
});

it('reports runs still missing a location 48 hours after ingest, but not newer or demo ones', function (): void {
    Queue::fake();
    $unresolved = [
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_resolved_at' => null,
        'location_attempts' => ActivityDetail::MAX_BACKFILL_ATTEMPTS,
    ];
    ActivityDetail::factory()->create([...$unresolved, 'created_at' => now()->subHours(48)]);
    ActivityDetail::factory()->create([...$unresolved, 'created_at' => now()->subHours(47)]);
    ActivityDetail::factory()->create([...$unresolved, 'start_lat' => null, 'start_lng' => null, 'created_at' => now()->subDays(5)]);
    ActivityDetail::factory()
        ->for(Activity::factory()->for(User::factory()->state(['is_demo' => true])))
        ->create([...$unresolved, 'created_at' => now()->subDays(5)]);

    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('persistentGap')->once()->with('geo:backfill-locations', 'location', 1);
    $this->app->instance(MaintainerAlerter::class, $alerter);

    $this->artisan('geo:backfill-locations')->assertSuccessful();
});

it('reports no gap once every run is resolved', function (): void {
    Queue::fake();
    ActivityDetail::factory()->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_resolved_at' => now()->subDays(3),
        'created_at' => now()->subDays(4),
    ]);

    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('persistentGap')->once()->with('geo:backfill-locations', 'location', 0);
    $this->app->instance(MaintainerAlerter::class, $alerter);

    $this->artisan('geo:backfill-locations')->assertSuccessful();
});
