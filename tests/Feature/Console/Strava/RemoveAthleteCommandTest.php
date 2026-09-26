<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Enums\StravaGrantEventType;
use App\Models\StravaConnection;
use App\Models\StravaGrantToken;
use App\Services\Strava\StravaGrantLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function fakeAthleteRemovalHttp(int $deauthorizeStatus = 200): void
{
    Http::fake([
        'https://www.strava.com/oauth/token' => Http::response([
            'access_token' => 'fresh-access',
            'refresh_token' => 'fresh-refresh',
            'expires_at' => Carbon::now()->addHours(6)->timestamp,
        ]),
        'https://www.strava.com/oauth/deauthorize' => Http::response(['access_token' => 'revoked-token'], $deauthorizeStatus),
    ]);
}

function athleteWithLiveGrant(): User
{
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create([
        'access_token' => 'live-access',
        'refresh_token' => 'live-refresh',
        'token_expires_at' => Carbon::now()->addHours(5),
    ]);
    app(StravaGrantLedger::class)->recordGrant(
        $connection->strava_athlete_id,
        $user->id,
        $connection->credential_version,
        $connection->refresh_token,
        StravaGrantEventType::Granted,
    );

    return $user;
}

it('releases the grant on Strava and then removes the account and everything it owns', function (): void {
    fakeAthleteRemovalHttp();
    Notification::fake();
    $user = athleteWithLiveGrant();
    Activity::factory()->for($user)->create();

    $this->artisan('strava:remove-athlete', ['user' => $user->id, '--force' => true])
        ->expectsOutputToContain('Removed user '.$user->id)
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.strava.com/oauth/deauthorize'
        && $request['access_token'] === 'fresh-access');

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(StravaConnection::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('user_id', $user->id)->exists())->toBeFalse();

    Notification::assertNothingSent();
});

it('removes the account anyway when Strava will not take the deauthorize', function (): void {
    fakeAthleteRemovalHttp(503);
    $user = athleteWithLiveGrant();
    $athleteId = $user->stravaConnection->strava_athlete_id;

    $this->artisan('strava:remove-athlete', ['user' => $user->id, '--force' => true])
        ->expectsOutputToContain('did not accept the deauthorize')
        ->assertSuccessful();

    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'https://www.strava.com/oauth/deauthorize')->count())
        ->toBe(1);
    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(StravaGrantToken::query()->where('strava_athlete_id', $athleteId)->sole()->refresh_token)->toBe('fresh-refresh');
});

it('removes an athlete whose grant is already revoked without calling Strava again', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create([
        'token_expires_at' => Carbon::now()->addHours(5),
        'revoked_at' => Carbon::now()->subDay(),
    ]);

    $this->artisan('strava:remove-athlete', ['user' => $user->id, '--force' => true])
        ->expectsOutputToContain('nothing to release')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(StravaConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('refuses the demo account, whose connection the seed owns', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    StravaConnection::factory()->for($user)->create(['token_expires_at' => Carbon::now()->addHours(5)]);

    $this->artisan('strava:remove-athlete', ['user' => $user->id, '--force' => true])
        ->assertFailed();

    Http::assertNothingSent();
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

it('fails on a user id that does not exist', function (): void {
    $this->artisan('strava:remove-athlete', ['user' => 9_999, '--force' => true])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});

it('asks before removing, and removes nothing when the answer is no', function (): void {
    $user = athleteWithLiveGrant();

    $this->artisan('strava:remove-athlete', ['user' => $user->id])
        ->expectsConfirmation('Release '.$user->name.' <'.$user->email.'> (id '.$user->id.') from Strava and permanently remove the account and all owned data? This cannot be undone.', 'no')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and($user->stravaConnection()->sole()->isRevoked())->toBeFalse();
});
