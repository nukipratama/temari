<?php

declare(strict_types=1);

use App\Enums\StravaReadPriority;
use App\Enums\StravaReadSource;
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

function revokedUser(): User
{
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['revoked_at' => now()]);

    return $user;
}

it('queues a detail fetch for a summary-only run', function (): void {
    $activity = Activity::factory()->for(hydratableUser())->summaryOnly()->create();

    expect(app(DetailHydrator::class)->hydrate($activity->id))->toBeTrue();

    Queue::assertPushed(IngestActivityJob::class, fn (IngestActivityJob $job): bool => $job->activityId === $activity->id
        && $job->source === StravaReadSource::Hydration);
});

it('queues browsing-driven hydration at background priority', function (): void {
    $activity = Activity::factory()->for(hydratableUser())->summaryOnly()->create();

    app(DetailHydrator::class)->hydrate($activity->id);

    Queue::assertPushed(
        IngestActivityJob::class,
        fn (IngestActivityJob $job): bool => $job->priority === StravaReadPriority::Background
            && $job->source === StravaReadSource::Hydration,
    );
});

it('does not queue a detail fetch it must not spend', function (string $owner, bool $summaryOnly, array $attributes): void {
    $user = match ($owner) {
        'connected' => hydratableUser(),
        'demo' => hydratableUser(isDemo: true),
        'revoked' => revokedUser(),
        'unconnected' => User::factory()->create(),
        default => throw new InvalidArgumentException("Unknown owner {$owner}"),
    };
    $factory = Activity::factory()->for($user);
    $activity = ($summaryOnly ? $factory->summaryOnly() : $factory)->create($attributes);

    expect(app(DetailHydrator::class)->hydrate($activity->id))->toBeFalse();

    Queue::assertNothingPushed();
})->with([
    'an already-detailed run' => ['connected', false, []],
    'the demo account' => ['demo', true, []],
    'a revoked connection' => ['revoked', true, []],
    'a user with no Strava connection at all' => ['unconnected', true, []],
    'a run whose detail fetch has exhausted its attempts' => ['connected', true, ['detail_fail_count' => Activity::MAX_DETAIL_FETCH_ATTEMPTS]],
]);

it('is a no-op for an unknown activity id', function (): void {
    expect(app(DetailHydrator::class)->hydrate(404))->toBeFalse();

    Queue::assertNothingPushed();
});
