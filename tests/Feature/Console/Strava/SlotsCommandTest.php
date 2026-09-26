<?php

declare(strict_types=1);

use App\Enums\StravaGrantEventType;
use App\Enums\StravaGrantReleaseStatus;
use App\Models\StravaConnection;
use App\Models\StravaGrantEvent;
use App\Models\StravaGrantToken;
use App\Models\User;
use App\Services\Strava\StravaGrantLedger;
use App\Services\Strava\StravaGrantReleaseResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function fakeSlotsGrantRelease(int $deauthorizeStatus = 200, string $refreshToken = 'fresh-refresh'): void
{
    Http::fake([
        'https://www.strava.com/oauth/token' => Http::response([
            'access_token' => 'fresh-access',
            'refresh_token' => $refreshToken,
            'expires_at' => Carbon::now()->addHours(6)->timestamp,
        ]),
        'https://www.strava.com/oauth/deauthorize' => Http::response(['access_token' => 'revoked'], $deauthorizeStatus),
    ]);
}

/** @return array{User, StravaConnection} */
function stravaSlotsHolder(bool $demo = false, bool $revoked = false): array
{
    $user = User::factory()->create(['is_demo' => $demo]);
    $connection = StravaConnection::factory()->for($user)->create([
        'strava_athlete_id' => 987_000 + $user->id,
        'revoked_at' => $revoked ? now() : null,
    ]);
    app(StravaGrantLedger::class)->recordGrant(
        $connection->strava_athlete_id,
        $user->id,
        $connection->credential_version,
        $connection->refresh_token,
        StravaGrantEventType::Granted,
    );

    return [$user, $connection];
}

it('lists current holders with release details and a total', function (): void {
    [$user, $connection] = stravaSlotsHolder();
    $grant = StravaGrantToken::query()->where('strava_athlete_id', $connection->strava_athlete_id)->sole();
    app(StravaGrantLedger::class)->recordReleaseOutcome(
        $grant,
        new StravaGrantReleaseResult(StravaGrantReleaseStatus::Failed, 'Strava returned HTTP 503.'),
        forced: false,
    );

    Artisan::call('strava:slots');
    $output = Artisan::output();

    expect($output)->toContain('Athlete ID')
        ->toContain((string) $connection->strava_athlete_id)
        ->toContain((string) $user->id)
        ->toContain('active')
        ->toContain('1')
        ->toContain('Strava returned HTTP 503.')
        ->toContain('Total: 1');
});

it('leaves the demo grant out of the holder list and total', function (): void {
    [, $realConnection] = stravaSlotsHolder();
    [, $demoConnection] = stravaSlotsHolder(demo: true);

    Artisan::call('strava:slots');
    $output = Artisan::output();

    expect($output)->toContain((string) $realConnection->strava_athlete_id)
        ->not->toContain((string) $demoConnection->strava_athlete_id)
        ->toContain('Total: 1');
});

it('releases a live grant and revokes only the local connection', function (): void {
    fakeSlotsGrantRelease();
    [$user, $connection] = stravaSlotsHolder();

    $this->artisan('strava:slots', [
        '--release' => (string) $connection->strava_athlete_id,
        '--force' => true,
    ])->expectsOutputToContain('Released Strava athlete '.$connection->strava_athlete_id)
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.strava.com/oauth/deauthorize'
        && $request['access_token'] === 'fresh-access');
    expect($connection->fresh()->isRevoked())->toBeTrue()
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(StravaGrantToken::query()->where('strava_athlete_id', $connection->strava_athlete_id)->exists())->toBeFalse()
        ->and(StravaGrantEvent::query()->where('strava_athlete_id', $connection->strava_athlete_id)->orderBy('id')->get()->last()->event)
        ->toBe(StravaGrantEventType::ForceReleased);
});

it('marks a live connection revoked but retains the rotated grant after release failure', function (): void {
    fakeSlotsGrantRelease(503, 'rotated-refresh');
    [, $connection] = stravaSlotsHolder();

    $this->artisan('strava:slots', [
        '--release' => (string) $connection->strava_athlete_id,
        '--force' => true,
    ])->expectsOutputToContain('Release failed for Strava athlete '.$connection->strava_athlete_id)
        ->assertFailed();

    expect($connection->fresh()->isRevoked())->toBeTrue()
        ->and(StravaGrantToken::query()->where('strava_athlete_id', $connection->strava_athlete_id)->sole()->refresh_token)
        ->toBe('rotated-refresh');
});

it('refuses an explicit release of the demo holder', function (): void {
    [, $connection] = stravaSlotsHolder(demo: true);

    $this->artisan('strava:slots', [
        '--release' => (string) $connection->strava_athlete_id,
        '--force' => true,
    ])->expectsOutputToContain('The demo user cannot be released.')
        ->assertFailed();

    Http::assertNothingSent();
    expect(StravaGrantToken::query()->where('strava_athlete_id', $connection->strava_athlete_id)->exists())->toBeTrue();
});

it('releases every non-demo orphan after one confirmation', function (): void {
    fakeSlotsGrantRelease();
    [, $orphan] = stravaSlotsHolder(revoked: true);
    [, $demo] = stravaSlotsHolder(demo: true, revoked: true);

    $this->artisan('strava:slots', ['--release-orphans' => true, '--force' => true])
        ->expectsOutputToContain('Released Strava athlete '.$orphan->strava_athlete_id)
        ->assertSuccessful();

    Http::assertSentCount(2);
    expect(StravaGrantToken::query()->where('strava_athlete_id', $orphan->strava_athlete_id)->exists())->toBeFalse()
        ->and(StravaGrantToken::query()->where('strava_athlete_id', $demo->strava_athlete_id)->exists())->toBeTrue();
});

it('leaves the holder untouched when an operator declines confirmation', function (): void {
    [, $connection] = stravaSlotsHolder();

    $this->artisan('strava:slots', ['--release' => (string) $connection->strava_athlete_id])
        ->expectsConfirmation('Release Strava athlete '.$connection->strava_athlete_id.' and free its slot?', 'no')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect($connection->fresh()->isRevoked())->toBeFalse()
        ->and(StravaGrantToken::query()->where('strava_athlete_id', $connection->strava_athlete_id)->exists())->toBeTrue();
});
