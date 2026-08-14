<?php

declare(strict_types=1);

use App\Enums\StravaReadPriority;
use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Run\Ingest\DetailHydrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
});

function hydratableUser(bool $isDemo = false): User
{
    $user = User::factory()->create(['is_demo' => $isDemo]);
    StravaConnection::factory()->for($user)->create(['revoked_at' => null]);

    return $user;
}

it('queues a detail fetch for a summary-only run', function (): void {
    $activity = Activity::factory()->for(hydratableUser())->summaryOnly()->create();

    expect(app(DetailHydrator::class)->hydrate($activity->id))->toBeTrue();

    Queue::assertPushed(IngestActivityJob::class, fn (IngestActivityJob $job): bool => $job->activityId === $activity->id);
});

it('queues browsing-driven hydration at background priority', function (): void {
    $activity = Activity::factory()->for(hydratableUser())->summaryOnly()->create();

    app(DetailHydrator::class)->hydrate($activity->id);

    Queue::assertPushed(
        IngestActivityJob::class,
        fn (IngestActivityJob $job): bool => $job->priority === StravaReadPriority::Background,
    );
});

it('leaves an already-detailed run alone', function (): void {
    $activity = Activity::factory()->for(hydratableUser())->create();

    expect(app(DetailHydrator::class)->hydrate($activity->id))->toBeFalse();

    Queue::assertNothingPushed();
});

it('never spends a Strava read on the demo account', function (): void {
    $activity = Activity::factory()->for(hydratableUser(isDemo: true))->summaryOnly()->create();

    expect(app(DetailHydrator::class)->hydrate($activity->id))->toBeFalse();

    Queue::assertNothingPushed();
});

it('skips a revoked connection', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['revoked_at' => now()]);
    $activity = Activity::factory()->for($user)->summaryOnly()->create();

    expect(app(DetailHydrator::class)->hydrate($activity->id))->toBeFalse();

    Queue::assertNothingPushed();
});

it('skips a user with no Strava connection at all', function (): void {
    $activity = Activity::factory()->for(User::factory())->summaryOnly()->create();

    expect(app(DetailHydrator::class)->hydrate($activity->id))->toBeFalse();

    Queue::assertNothingPushed();
});

it('is a no-op for an unknown activity id', function (): void {
    expect(app(DetailHydrator::class)->hydrate(404))->toBeFalse();

    Queue::assertNothingPushed();
});
