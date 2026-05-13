<?php

declare(strict_types=1);

use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('queues a resolve job for each unresolved detail with coords', function (): void {
    Queue::fake();
    $user = User::factory()->create();

    [$a, $b] = Activity::factory()->for($user)->count(2)->create();
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
    $user = User::factory()->create();

    [$resolved, $missing, $unresolved] = Activity::factory()->for($user)->count(3)->create();
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

it('honors the --limit option', function (): void {
    Queue::fake();
    $user = User::factory()->create();

    Activity::factory()
        ->for($user)
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

it('backfills start_lat/start_lng from summary_polyline when coords are null', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
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
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_lat' => null,
        'start_lng' => null,
        'summary_polyline' => '_', // truncated → decoder returns null
    ]);

    $this->artisan('geo:backfill-locations')->assertSuccessful();

    expect($detail->fresh()->start_lat)->toBeNull();
    Queue::assertNothingPushed();
});
