<?php

declare(strict_types=1);

use App\Enums\StravaGrantEventType;
use App\Enums\StravaGrantReleaseStatus;
use App\Models\StravaConnection;
use App\Models\StravaGrantEvent;
use App\Models\StravaGrantToken;
use App\Models\User;
use App\Services\Strava\StravaClient;
use App\Services\Strava\StravaGrantLedger;
use App\Services\Strava\StravaGrantReleaseService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function fakeGrantReleaseHttp(
    int $deauthorizeStatus = 200,
    array $deauthorizeBody = ['access_token' => 'revoked'],
    string $refreshToken = 'fresh-refresh',
): void {
    Http::fake([
        'https://www.strava.com/oauth/token' => Http::response([
            'access_token' => 'fresh-access',
            'refresh_token' => $refreshToken,
            'expires_at' => Carbon::now()->addHours(6)->timestamp,
        ]),
        'https://www.strava.com/oauth/deauthorize' => Http::response($deauthorizeBody, $deauthorizeStatus),
    ]);
}

it('records a confirmed operator release and removes its token', function (): void {
    fakeGrantReleaseHttp();
    app(StravaGrantLedger::class)->recordGrant(12345, 7, 4, 'stale-refresh', StravaGrantEventType::Granted);

    $result = app(StravaGrantReleaseService::class)->release(
        12345,
        forced: true,
        expectedCredentialVersion: 4,
    );

    expect($result?->status)->toBe(StravaGrantReleaseStatus::Released)
        ->and(StravaGrantToken::query()->where('strava_athlete_id', 12345)->exists())->toBeFalse()
        ->and(StravaGrantEvent::query()->where('strava_athlete_id', 12345)->orderBy('id')->get()->map(fn (StravaGrantEvent $event) => $event->event)->all())
        ->toBe([StravaGrantEventType::Granted, StravaGrantEventType::ForceReleased]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.strava.com/oauth/deauthorize'
        && $request['access_token'] === 'fresh-access');
});

it('retains the rotated token and error after a failed release', function (): void {
    fakeGrantReleaseHttp(503, [], 'rotated-refresh');
    app(StravaGrantLedger::class)->recordGrant(12345, 7, 4, 'stale-refresh', StravaGrantEventType::Granted);

    $result = app(StravaGrantReleaseService::class)->release(12345, expectedCredentialVersion: 4);

    expect($result?->status)->toBe(StravaGrantReleaseStatus::Failed)
        ->and(StravaGrantToken::query()->where('strava_athlete_id', 12345)->sole()->refresh_token)->toBe('rotated-refresh')
        ->and(StravaGrantEvent::query()->where('strava_athlete_id', 12345)->orderBy('id')->get()->last()->event)
        ->toBe(StravaGrantEventType::ReleaseFailed);
});

it('keeps the token when the refresh 400 blames our client credentials', function (): void {
    Http::fake([
        'https://www.strava.com/oauth/token' => Http::response(stravaBadRequest('Application', 'client_secret'), 400),
    ]);
    app(StravaGrantLedger::class)->recordGrant(12345, 7, 4, 'stale-refresh', StravaGrantEventType::Granted);

    $result = app(StravaGrantReleaseService::class)->release(12345, expectedCredentialVersion: 4);

    expect($result?->status)->toBe(StravaGrantReleaseStatus::Failed)
        ->and($result?->error)->toContain('(Application client_secret)')
        ->and(StravaGrantToken::query()->where('strava_athlete_id', 12345)->sole()->refresh_token)->toBe('stale-refresh')
        ->and(StravaGrantEvent::query()->where('strava_athlete_id', 12345)->orderBy('id')->get()->last()->event)
        ->toBe(StravaGrantEventType::ReleaseFailed);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://www.strava.com/oauth/deauthorize');
});

it('removes the token when the refresh 400 rejects the refresh token', function (): void {
    Http::fake([
        'https://www.strava.com/oauth/token' => Http::response(stravaBadRequest('RefreshToken', 'refresh_token'), 400),
    ]);
    app(StravaGrantLedger::class)->recordGrant(12345, 7, 4, 'stale-refresh', StravaGrantEventType::Granted);

    $result = app(StravaGrantReleaseService::class)->release(12345, expectedCredentialVersion: 4);

    expect($result?->status)->toBe(StravaGrantReleaseStatus::Rejected)
        ->and(StravaGrantToken::query()->where('strava_athlete_id', 12345)->exists())->toBeFalse()
        ->and(StravaGrantEvent::query()->where('strava_athlete_id', 12345)->orderBy('id')->get()->last()->event)
        ->toBe(StravaGrantEventType::Rejected);
});

it('returns failed without sending requests when the refresh lock is busy', function (): void {
    Http::fake();
    app(StravaGrantLedger::class)->recordGrant(12345, 7, 4, 'stale-refresh', StravaGrantEventType::Granted);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')
        ->once()
        ->with(StravaClient::REFRESH_LOCK_TTL_SECONDS, Mockery::type(Closure::class))
        ->andThrow(new LockTimeoutException());
    Cache::shouldReceive('lock')
        ->once()
        ->with(StravaClient::refreshLockKey(12345), StravaClient::REFRESH_LOCK_TTL_SECONDS)
        ->andReturn($lock);

    $result = app(StravaGrantReleaseService::class)->release(12345, expectedCredentialVersion: 4);

    expect($result?->status)->toBe(StravaGrantReleaseStatus::Failed)
        ->and($result?->error)->toBe('refresh lock busy');
    Http::assertNothingSent();
});

it('removes the token when Strava reports invalid_grant during deauthorization', function (): void {
    fakeGrantReleaseHttp(400, ['error' => 'invalid_grant']);
    app(StravaGrantLedger::class)->recordGrant(12345, 7, 4, 'stale-refresh', StravaGrantEventType::Granted);

    $result = app(StravaGrantReleaseService::class)->release(12345, expectedCredentialVersion: 4);

    expect($result?->status)->toBe(StravaGrantReleaseStatus::Rejected)
        ->and(StravaGrantToken::query()->where('strava_athlete_id', 12345)->exists())->toBeFalse()
        ->and(StravaGrantEvent::query()->where('strava_athlete_id', 12345)->orderBy('id')->get()->last()->event)
        ->toBe(StravaGrantEventType::Rejected);
});

it('retains the token when deauthorization returns a redirect instead of acceptance', function (): void {
    fakeGrantReleaseHttp(302, []);
    app(StravaGrantLedger::class)->recordGrant(12345, 7, 4, 'stale-refresh', StravaGrantEventType::Granted);

    $result = app(StravaGrantReleaseService::class)->release(12345, expectedCredentialVersion: 4);

    expect($result?->status)->toBe(StravaGrantReleaseStatus::Failed)
        ->and(StravaGrantToken::query()->where('strava_athlete_id', 12345)->sole()->refresh_token)->toBe('fresh-refresh');
});

it('ignores a release request captured before a reconnect', function (): void {
    Http::fake();
    $ledger = app(StravaGrantLedger::class);
    $ledger->recordGrant(12345, 7, 4, 'old-refresh', StravaGrantEventType::Granted);
    $ledger->recordGrant(12345, 7, 5, 'new-refresh', StravaGrantEventType::Reconnected);

    $result = app(StravaGrantReleaseService::class)->release(12345, expectedCredentialVersion: 4);

    expect($result?->status)->toBe(StravaGrantReleaseStatus::Stale)
        ->and(StravaGrantToken::query()->where('strava_athlete_id', 12345)->sole()->refresh_token)->toBe('new-refresh');
    Http::assertNothingSent();
});

it('selects non-demo grants without an active local connection', function (): void {
    Http::fake();
    $activeUser = User::factory()->create();
    StravaConnection::factory()->for($activeUser)->create(['strava_athlete_id' => 111]);
    app(StravaGrantLedger::class)->recordGrant(111, $activeUser->id, 0, 'active', StravaGrantEventType::Granted);

    $orphanUser = User::factory()->create();
    $revokedConnection = StravaConnection::factory()->for($orphanUser)->create([
        'strava_athlete_id' => 222,
        'revoked_at' => now(),
    ]);
    app(StravaGrantLedger::class)->recordGrant(222, $orphanUser->id, 0, 'orphan', StravaGrantEventType::Granted);

    $demoUser = User::factory()->create(['is_demo' => true]);
    app(StravaGrantLedger::class)->recordGrant(333, $demoUser->id, 0, 'demo', StravaGrantEventType::Granted);

    $deletedUser = User::factory()->create();
    $deletedConnection = StravaConnection::factory()->for($deletedUser)->create(['strava_athlete_id' => 444]);
    app(StravaGrantLedger::class)->recordGrant(444, $deletedUser->id, 0, 'deleted', StravaGrantEventType::Granted);
    $deletedUser->delete();

    expect(app(StravaGrantReleaseService::class)->orphanGrants()->pluck('strava_athlete_id')->all())
        ->toBe([$revokedConnection->strava_athlete_id, $deletedConnection->strava_athlete_id]);
});
