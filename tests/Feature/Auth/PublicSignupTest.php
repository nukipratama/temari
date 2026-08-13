<?php

declare(strict_types=1);

use App\Enums\IngestState;
use App\Jobs\Strava\IngestActivityJob;
use App\Jobs\Strava\SyncActivitiesJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Run\Ingest\SyncOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::clear('strava-api:15min');
    RateLimiter::clear('strava-api:daily');
});

/**
 * A Strava athlete with no row anywhere in this database.
 */
function strangerAthlete(int $athleteId = 4_411_902): SocialiteUser
{
    $athlete = Mockery::mock(SocialiteUser::class);
    $athlete->token = 'stranger-access';
    $athlete->refreshToken = 'stranger-refresh';
    $athlete->expiresIn = 21_600;
    $athlete->shouldReceive('getId')->andReturn((string) $athleteId);
    $athlete->shouldReceive('getName')->andReturn('Rin Okumura');
    $athlete->shouldReceive('getEmail')->andReturn("athlete-{$athleteId}@example.test");
    $athlete->shouldReceive('getAvatar')->andReturn('https://strava.test/rin.png');

    /** @var SocialiteUser */
    return $athlete;
}

/**
 * @return list<array<string, mixed>>
 */
function strangerHistory(int $count): array
{
    return array_map(fn (int $offset): array => [
        'id' => 7_700_000 - $offset,
        'sport_type' => 'Run',
        'name' => 'Evening run',
        'start_date' => CarbonImmutable::parse('2026-05-10T11:00:00Z')->subDays($offset)->toIso8601String(),
        'start_date_local' => CarbonImmutable::parse('2026-05-10T18:00:00')->subDays($offset)->toIso8601String(),
        'distance' => 9_400.0,
        'moving_time' => 3_120,
        'elapsed_time' => 3_190,
        'average_speed' => 3.01,
        'total_elevation_gain' => 62.0,
        'has_heartrate' => true,
        'average_heartrate' => 148.0,
        'max_heartrate' => 169,
    ], range(0, $count - 1));
}

it('lets an athlete nobody has ever seen sign up, and never marks them demo', function (): void {
    Bus::fake();
    mockStravaDriver(fn ($driver) => $driver->shouldReceive('user')->once()->andReturn(strangerAthlete()));

    expect(User::query()->count())->toBe(0);

    $this->get(route('auth.strava.callback'))->assertRedirect(route('onboarding.show'));

    $this->assertAuthenticated();
    $user = User::query()->sole();

    expect($user->is_demo)->toBeFalse()
        ->and($user->onboarded_at)->toBeNull()
        ->and(StravaConnection::query()->where('user_id', $user->id)->value('strava_athlete_id'))->toBe(4_411_902);

    // A stranger is a billable user, which is the point: they are inside the
    // schedulers' scope precisely where the demo account is outside it.
    expect(User::query()->notDemo()->pluck('id')->all())->toBe([$user->id]);

    Bus::assertDispatched(SyncActivitiesJob::class, fn (SyncActivitiesJob $job): bool => $job->userId === $user->id);
});

it('carries a stranger from OAuth to a working dashboard on summary data alone', function (): void {
    Bus::fake();
    mockStravaDriver(fn ($driver) => $driver->shouldReceive('user')->once()->andReturn(strangerAthlete()));

    $this->get(route('auth.strava.callback'))->assertRedirect(route('onboarding.show'));
    $user = User::query()->sole();

    // The backfill the callback queued, run for real against a faked Strava. A
    // 5xx on the per-activity endpoint proves onboarding never needs it.
    Http::fake([
        'strava.com/api/v3/athlete/activities*' => Http::response(strangerHistory(12)),
        'strava.com/api/v3/activities/*' => Http::response(['detail' => 'must not be fetched'], 500),
    ]);
    app(SyncOrchestrator::class)->syncUser($user->refresh());

    expect(Activity::query()->where('user_id', $user->id)->count())->toBe(12)
        ->and(Activity::query()->where('user_id', $user->id)->summaryOnly()->count())->toBe(12);
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '/api/v3/activities/'));
    Bus::assertNotDispatched(IngestActivityJob::class);

    // The wizard is reachable and completes without a single hydrated run.
    $this->actingAs($user)->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Onboarding/Index'));

    $this->actingAs($user)->post(route('onboarding.store'), [])
        ->assertRedirect(route('dashboard'));

    expect($user->refresh()->onboarded_at)->not->toBeNull();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home'));

    $this->actingAs($user)->get(route('activities.index'))->assertOk();
});

it('tells a stranger their first opened run is still filling in', function (): void {
    Bus::fake();
    mockStravaDriver(fn ($driver) => $driver->shouldReceive('user')->once()->andReturn(strangerAthlete()));

    $this->get(route('auth.strava.callback'));
    $user = User::query()->sole();
    $user->markOnboarded();

    Http::fake([
        'strava.com/api/v3/athlete/activities*' => Http::response(strangerHistory(3)),
    ]);
    app(SyncOrchestrator::class)->syncUser($user->refresh());

    $activity = Activity::query()->where('user_id', $user->id)->firstOrFail();
    expect($activity->ingest_state)->toBe(IngestState::Summary);

    $this->actingAs($user)->get(route('activities.show', $activity))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Show')
            ->where('awaitingDetail', true)
            ->where('card', null));

    Bus::assertDispatched(IngestActivityJob::class, fn (IngestActivityJob $job): bool => $job->activityId === $activity->id);

    // Once the detail lands, the notice's own flag goes away.
    $activity->update(['ingest_state' => IngestState::Detailed]);
    ActivityDetail::query()->where('activity_id', $activity->id)->update(['trimp_edwards' => 88]);

    $this->actingAs($user)->get(route('activities.show', $activity))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('awaitingDetail', false));
});

it('gives a second stranger their own account rather than joining the first', function (): void {
    Bus::fake();

    mockStravaDriver(fn ($driver) => $driver->shouldReceive('user')->once()->andReturn(strangerAthlete(4_411_902)));
    $this->get(route('auth.strava.callback'));
    $first = User::query()->sole();

    Auth::logout();
    $this->flushSession();

    mockStravaDriver(fn ($driver) => $driver->shouldReceive('user')->once()->andReturn(strangerAthlete(5_002_113)));
    $this->get(route('auth.strava.callback'))->assertRedirect(route('onboarding.show'));

    expect(User::query()->count())->toBe(2)
        ->and(User::query()->where('id', '!=', $first->id)->sole()->is_demo)->toBeFalse()
        ->and(StravaConnection::query()->pluck('strava_athlete_id')->sort()->values()->all())
        ->toBe([4_411_902, 5_002_113]);
});
