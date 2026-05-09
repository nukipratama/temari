<?php

declare(strict_types=1);

use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

it('shows the login page to guests', function (): void {
    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSee('Connect with Strava');
});

it('redirects authenticated users away from login', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));
});

it('redirects unauthenticated visitors from root to login', function (): void {
    $this->get('/')->assertRedirect(route('login'));
});

it('redirects authenticated visitors from root to dashboard', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('dashboard'));
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
});

it('updates an existing user on subsequent strava callbacks', function (): void {
    $existingUser = User::factory()->create(['name' => 'Old Name']);
    StravaConnection::factory()->create([
        'user_id' => $existingUser->id,
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
    StravaConnection::factory()->create([
        'user_id' => $user->id,
        'strava_athlete_id' => 555111,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Ada Lovelace')
        ->assertSee('555111');
});
