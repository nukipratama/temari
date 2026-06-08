<?php

declare(strict_types=1);

use App\Jobs\Strava\SyncActivitiesJob;
use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

// The callback dispatches a backfill job on a fresh connect; fake the bus so it
// is recorded rather than run inline against the real Strava API.
beforeEach(fn () => Bus::fake());

it('shows the login page to guests', function (): void {
    $this->get(route('login'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->has('authStravaUrl'));
});

it('redirects authenticated users away from login', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));
});

it('redirects unauthenticated visitors from root to login', function (): void {
    $this->get('/')->assertRedirect(route('login'));
});

it('renders dashboard for authenticated visitors at root', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertSuccessful();
});

it('redirects to strava on the redirect endpoint', function (): void {
    mockStravaDriver(function ($driver): void {
        $driver->shouldReceive('scopes')->once()->with(['read', 'activity:read_all'])->andReturnSelf();
        $driver->shouldReceive('redirect')->once()->andReturn(redirect('https://www.strava.com/oauth/authorize?fake'));
    });

    $this->get(route('auth.strava.redirect'))
        ->assertRedirect('https://www.strava.com/oauth/authorize?fake');
});

it('creates a new user from the strava callback and logs them in', function (): void {
    $stravaUser = Mockery::mock(SocialiteUser::class);
    $stravaUser->token = 'access-token-xyz';
    $stravaUser->refreshToken = 'refresh-token-xyz';
    $stravaUser->expiresIn = 21600;
    $stravaUser->shouldReceive('getId')->andReturn('987654');
    $stravaUser->shouldReceive('getName')->andReturn('Ada Lovelace');
    $stravaUser->shouldReceive('getEmail')->andReturn('athlete@example.test');
    $stravaUser->shouldReceive('getAvatar')->andReturn('https://strava.test/avatar.png');

    mockStravaDriver(fn ($driver) => $driver->shouldReceive('user')->once()->andReturn($stravaUser));

    $this->startSession();
    $sessionIdBefore = session()->getId();

    $response = $this->get(route('auth.strava.callback'));

    $response->assertRedirect(route('dashboard'))
        ->assertCookie(Auth::guard()->getRecallerName());

    expect(session()->getId())->not->toBe($sessionIdBefore);
    $this->assertAuthenticated();

    $connection = StravaConnection::where('strava_athlete_id', 987654)->firstOrFail();
    $user = $connection->user;

    expect($connection->strava_athlete_id)->toBe(987654)
        ->and(Carbon::now()->addSeconds(21600)->diffInSeconds($connection->token_expires_at, true))
        ->toBeLessThan(5);

    expect($user->name)->toBe('Ada Lovelace')
        ->and($user->email)->toBe('athlete@example.test')
        ->and($user->avatar_url)->toBe('https://strava.test/avatar.png')
        ->and($connection->access_token)->toBe('access-token-xyz')
        ->and($connection->refresh_token)->toBe('refresh-token-xyz')
        ->and($connection->scopes)->toBe('read,activity:read_all');

    // First connect kicks off a full-history backfill (no single-activity scope).
    Bus::assertDispatched(
        SyncActivitiesJob::class,
        fn (SyncActivitiesJob $job): bool => $job->userId === $user->id && $job->stravaActivityId === null,
    );
});

it('stores only the granted scopes and logs when a required scope is declined', function (): void {
    Log::spy();

    $stravaUser = Mockery::mock(SocialiteUser::class);
    $stravaUser->token = 'access-token-partial';
    $stravaUser->refreshToken = 'refresh-token-partial';
    $stravaUser->expiresIn = 21600;
    $stravaUser->shouldReceive('getId')->andReturn('555111');
    $stravaUser->shouldReceive('getName')->andReturn('Partial Grant');
    $stravaUser->shouldReceive('getEmail')->andReturn('partial@example.test');
    $stravaUser->shouldReceive('getAvatar')->andReturn('https://strava.test/p.png');

    mockStravaDriver(fn ($driver) => $driver->shouldReceive('user')->once()->andReturn($stravaUser));

    // Strava reports only `read` was granted; activity:read_all was declined.
    $this->get(route('auth.strava.callback', ['scope' => 'read']))
        ->assertRedirect(route('dashboard'));

    $connection = StravaConnection::where('strava_athlete_id', 555111)->firstOrFail();
    expect($connection->scopes)->toBe('read');

    Log::shouldHaveReceived('warning')->once()->with('strava.scopes.partial', Mockery::on(
        fn (array $ctx): bool => $ctx['missing'] === ['activity:read_all'] && $ctx['granted'] === 'read',
    ));
});

it('updates an existing user on subsequent strava callbacks', function (): void {
    $existingUser = User::factory()->create(['name' => 'Old Name']);
    StravaConnection::factory()->for($existingUser)->create([
        'strava_athlete_id' => 987654,
        'access_token' => 'old-access',
        'refresh_token' => 'old-refresh',
    ]);

    $stravaUser = Mockery::mock(SocialiteUser::class);
    $stravaUser->token = 'new-access';
    $stravaUser->refreshToken = 'new-refresh';
    $stravaUser->expiresIn = 21600;
    $stravaUser->shouldReceive('getId')->andReturn('987654');
    $stravaUser->shouldReceive('getName')->andReturn('New Name');
    $stravaUser->shouldReceive('getEmail')->andReturn('athlete@example.test');
    $stravaUser->shouldReceive('getAvatar')->andReturn('https://strava.test/new.png');

    mockStravaDriver(fn ($driver) => $driver->shouldReceive('user')->once()->andReturn($stravaUser));

    $this->get(route('auth.strava.callback'))->assertRedirect(route('dashboard'));

    expect(User::count())->toBe(1)
        ->and(StravaConnection::count())->toBe(1);

    $existingUser->refresh()->load('stravaConnection');
    expect($existingUser->name)->toBe('New Name')
        ->and($existingUser->stravaConnection->access_token)->toBe('new-access')
        ->and($existingUser->stravaConnection->refresh_token)->toBe('new-refresh');

    // Re-login on an existing connection must NOT re-trigger a backfill.
    Bus::assertNotDispatched(SyncActivitiesJob::class);
});

it('redirects back to login when strava returns an error', function (): void {
    $this->get(route('auth.strava.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['strava' => 'Strava authorization was cancelled or denied.']);

    $this->assertGuest();
});

it('redirects back to login when fetching the strava user fails', function (): void {
    mockStravaDriver(fn ($driver) => $driver->shouldReceive('user')->once()->andThrow(new InvalidStateException()));

    $this->get(route('auth.strava.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['strava' => 'We could not complete the Strava sign-in. Please try again.']);

    $this->assertGuest();
});

it('logs the user out, clears session data, and redirects to login', function (): void {
    $this->actingAs(User::factory()->create())
        ->withSession(['carry_over' => 'pre-logout'])
        ->startSession();
    $sessionIdBefore = session()->getId();

    $this->post(route('auth.logout'))->assertRedirect(route('login'));

    expect(session()->getId())->not->toBe($sessionIdBefore)
        ->and(session('carry_over'))->toBeNull();
    $this->assertGuest();
});

it('blocks guests from the dashboard', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('shows the dashboard to authenticated users', function (): void {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);
    StravaConnection::factory()->for($user)->create([
        'strava_athlete_id' => 555111,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HariIni')
            ->where('auth.user.name', 'Ada Lovelace'));
});
