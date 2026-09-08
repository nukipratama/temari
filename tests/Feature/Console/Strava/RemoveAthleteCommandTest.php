<?php

declare(strict_types=1);

use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake();
});

function athleteWithLiveGrant(): User
{
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create([
        'access_token' => 'live-access',
        'token_expires_at' => Carbon::now()->addHours(5),
    ]);

    return $user;
}

it('releases the grant on Strava and revokes the connection locally, keeping the account', function (): void {
    $user = athleteWithLiveGrant();

    $this->artisan('strava:remove-athlete', ['user' => $user->id, '--force' => true])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.strava.com/oauth/deauthorize'
        && $request['access_token'] === 'live-access');

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and($user->stravaConnection()->sole()->isRevoked())->toBeTrue();
});

it('revokes the connection anyway when Strava will not take the deauthorize', function (): void {
    Http::fake(['https://www.strava.com/oauth/deauthorize' => fn () => throw new ConnectionException('Strava unreachable')]);
    $user = athleteWithLiveGrant();

    $this->artisan('strava:remove-athlete', ['user' => $user->id, '--force' => true])
        ->expectsOutputToContain('did not accept the deauthorize')
        ->assertSuccessful();

    expect($user->stravaConnection()->sole()->isRevoked())->toBeTrue();
});

it('does nothing for an athlete whose grant is already revoked', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create([
        'token_expires_at' => Carbon::now()->addHours(5),
        'revoked_at' => Carbon::now()->subDay(),
    ]);

    $this->artisan('strava:remove-athlete', ['user' => $user->id, '--force' => true])
        ->expectsOutputToContain('Nothing to release')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('refuses the demo account, whose connection the seed owns', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    StravaConnection::factory()->for($user)->create(['token_expires_at' => Carbon::now()->addHours(5)]);

    $this->artisan('strava:remove-athlete', ['user' => $user->id, '--force' => true])
        ->assertFailed();

    Http::assertNothingSent();
});

it('fails on a user id that does not exist', function (): void {
    $this->artisan('strava:remove-athlete', ['user' => 9_999, '--force' => true])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});

it('asks before releasing, and releases nothing when the answer is no', function (): void {
    $user = athleteWithLiveGrant();

    $this->artisan('strava:remove-athlete', ['user' => $user->id])
        ->expectsConfirmation('Release '.$user->name.' <'.$user->email.'> (id '.$user->id.') from Strava? They will have to reconnect to sync again.', 'no')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect($user->stravaConnection()->sole()->isRevoked())->toBeFalse();
});
