<?php

declare(strict_types=1);

use App\Enums\IngestState;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\AI\HydrationBacklog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('reads one athlete\'s connect timestamp', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::parse('2026-09-01 12:00:00')]);

    expect(app(HydrationBacklog::class)->connectedAt($user->id)?->toDateTimeString())->toBe('2026-09-01 12:00:00');
});

it('returns null for an athlete who never connected', function (): void {
    expect(app(HydrationBacklog::class)->connectedAt(User::factory()->create()->id))->toBeNull();
});

it('reads many athletes\' connect timestamps at once, keyed by user', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    StravaConnection::factory()->for($first)->create(['created_at' => Carbon::parse('2026-09-01 12:00:00')]);
    StravaConnection::factory()->for($second)->create(['created_at' => Carbon::parse('2026-09-05 08:00:00')]);

    $connected = app(HydrationBacklog::class)->connectedAtFor([$first->id, $second->id]);

    expect($connected[$first->id]->toDateTimeString())->toBe('2026-09-01 12:00:00')
        ->and($connected[$second->id]->toDateTimeString())->toBe('2026-09-05 08:00:00');
});

it('finds only the runs the pipeline still owes a hydration', function (): void {
    $user = User::factory()->create();

    $summaryOnly = Activity::factory()->for($user)->create(['ingest_state' => IngestState::Summary]);
    ActivityDetail::factory()->for($summaryOnly)->create(['start_date_local' => Carbon::parse('2026-08-01 06:00:00')]);

    $hydrated = Activity::factory()->for($user)->create(['ingest_state' => IngestState::Detailed]);
    ActivityDetail::factory()->for($hydrated)->create(['start_date_local' => Carbon::parse('2026-08-02 06:00:00')]);

    $ids = app(HydrationBacklog::class)->awaitingHydration([$user->id])->pluck('activities.id')->all();

    expect($ids)->toBe([$summaryOnly->id]);
});

it('scopes the backlog to the athletes asked about', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $other = Activity::factory()->for($theirs)->create(['ingest_state' => IngestState::Summary]);
    ActivityDetail::factory()->for($other)->create(['start_date_local' => Carbon::parse('2026-08-01 06:00:00')]);

    expect(app(HydrationBacklog::class)->awaitingHydration([$mine->id])->exists())->toBeFalse();
});
