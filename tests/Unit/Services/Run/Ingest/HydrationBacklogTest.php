<?php

declare(strict_types=1);

use App\Enums\IngestState;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Run\Ingest\HydrationBacklog;
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

it('tells whether a run dated inside a range still awaits hydration', function (): void {
    $user = User::factory()->create();
    $pending = Activity::factory()->for($user)->create(['ingest_state' => IngestState::Summary, 'analyzed_at' => null]);
    ActivityDetail::factory()->for($pending)->create(['start_date_local' => Carbon::parse('2026-03-01 06:00:00')]);
    $backlog = app(HydrationBacklog::class);

    expect($backlog->awaitsHydrationBefore($user->id, Carbon::parse('2026-04-01')))->toBeTrue()
        ->and($backlog->awaitsHydrationBefore($user->id, Carbon::parse('2026-02-01')))->toBeFalse()
        ->and($backlog->awaitsHydrationBefore($user->id, Carbon::parse('2026-04-01'), Carbon::parse('2026-03-15')))->toBeFalse()
        ->and($backlog->awaitsHydrationBefore($user->id, null))->toBeFalse();
});

it('reports whether a run inside the trailing CTL window still awaits hydration', function (): void {
    $user = User::factory()->create();
    $today = Carbon::parse('2026-09-15');
    $recent = Activity::factory()->for($user)->create(['ingest_state' => IngestState::Summary]);
    ActivityDetail::factory()->for($recent)->create(['start_date_local' => $today->copy()->subDays(41)->setTime(7, 0)]);

    expect(app(HydrationBacklog::class)->recentLoadAwaitsScoring($user->id, $today))->toBeTrue();
});

it('ignores an unscored run outside the trailing CTL window', function (): void {
    $user = User::factory()->create();
    $today = Carbon::parse('2026-09-15');
    $old = Activity::factory()->for($user)->create(['ingest_state' => IngestState::Summary]);
    ActivityDetail::factory()->for($old)->create(['start_date_local' => $today->copy()->subDays(42)->setTime(7, 0)]);

    expect(app(HydrationBacklog::class)->recentLoadAwaitsScoring($user->id, $today))->toBeFalse();
});

function hydrationAthleteConnectedAt(string $connectedAt = '2026-09-01 12:00:00'): User
{
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::parse($connectedAt)]);

    return $user;
}

function hydrationRunFor(User $user, string $startedAt, IngestState $state = IngestState::Detailed): Activity
{
    $activity = Activity::factory()->for($user)->create([
        'ingest_state' => $state,
        'analyzed_at' => $state === IngestState::Detailed ? Carbon::now() : null,
    ]);
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse($startedAt)]);

    return $activity;
}

describe('history hydration holds', function (): void {
    beforeEach(function (): void {
        Carbon::setTestNow('2026-09-15 09:00:00');
    });

    afterEach(function (): void {
        Carbon::setTestNow();
    });

    it('holds automatic narration while an older run within past-you reach still hydrates, inside the grace window', function (): void {
        $user = hydrationAthleteConnectedAt('2026-09-15 08:00:00');
        hydrationRunFor($user, '2025-11-26 06:00:00', IngestState::Summary);

        expect(app(HydrationBacklog::class)->awaitsOlderHydration($user->id, Carbon::parse('2026-09-13 06:00:00')))
            ->toBeTrue();
    });

    it('releases automatic narration once the grace window after connecting has passed', function (): void {
        $user = hydrationAthleteConnectedAt('2026-09-13 08:00:00');
        hydrationRunFor($user, '2025-11-26 06:00:00', IngestState::Summary);

        expect(app(HydrationBacklog::class)->awaitsOlderHydration($user->id, Carbon::parse('2026-09-12 06:00:00')))
            ->toBeFalse();
    });

    it('does not hold automatic narration on history past past-you reach', function (): void {
        $user = hydrationAthleteConnectedAt('2026-09-15 08:00:00');
        hydrationRunFor($user, '2025-09-01 06:00:00', IngestState::Summary);

        expect(app(HydrationBacklog::class)->awaitsOlderHydration($user->id, Carbon::parse('2026-09-13 06:00:00')))
            ->toBeFalse();
    });

    it('holds full hydration while any run of the backlog is unhydrated, even outside past-you reach', function (): void {
        $user = hydrationAthleteConnectedAt('2026-09-15 08:00:00');
        hydrationRunFor($user, '2020-01-01 06:00:00', IngestState::Summary);

        expect(app(HydrationBacklog::class)->awaitsFullHydration($user->id))->toBeTrue();
    });

    it('releases full hydration once every run of the backlog has hydrated', function (): void {
        $user = hydrationAthleteConnectedAt('2026-09-15 08:00:00');
        hydrationRunFor($user, '2020-01-01 06:00:00');

        expect(app(HydrationBacklog::class)->awaitsFullHydration($user->id))->toBeFalse();
    });

    it('releases full hydration once the grace window after connecting has passed, despite a stuck backlog entry', function (): void {
        $user = hydrationAthleteConnectedAt('2026-09-13 08:00:00');
        hydrationRunFor($user, '2020-01-01 06:00:00', IngestState::Summary);

        expect(app(HydrationBacklog::class)->awaitsFullHydration($user->id))->toBeFalse();
    });

    it('never holds full hydration for an athlete with no Strava connection', function (): void {
        $user = User::factory()->create();
        hydrationRunFor($user, '2020-01-01 06:00:00', IngestState::Summary);

        expect(app(HydrationBacklog::class)->awaitsFullHydration($user->id))->toBeFalse();
    });
});
