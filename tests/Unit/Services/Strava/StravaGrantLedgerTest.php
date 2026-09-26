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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('mirrors connection refreshes through a credential-version checked write', function (): void {
    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create([
        'credential_version' => 3,
        'scopes' => 'read,activity:read_all',
    ]);
    $ledger = app(StravaGrantLedger::class);
    $ledger->recordGrant(
        $connection->strava_athlete_id,
        $user->id,
        3,
        $connection->refresh_token,
        StravaGrantEventType::Granted,
    );

    expect($ledger->persistConnectionRefresh(
        $connection,
        3,
        'fresh-access',
        'fresh-refresh',
        Carbon::now()->addHours(6),
    ))->toBeTrue();

    $connection->refresh();
    $grant = StravaGrantToken::query()->where('strava_athlete_id', $connection->strava_athlete_id)->sole();

    expect($connection->access_token)->toBe('fresh-access')
        ->and($connection->refresh_token)->toBe('fresh-refresh')
        ->and($connection->scopes)->toBe('read,activity:read_all')
        ->and($grant->refresh_token)->toBe('fresh-refresh');

    $connection->update(['credential_version' => 4]);

    expect($ledger->persistConnectionRefresh(
        $connection,
        3,
        'stale-access',
        'stale-refresh',
        Carbon::now()->addHours(6),
    ))->toBeFalse()
        ->and($grant->fresh()->refresh_token)->toBe('fresh-refresh');
});

it('keeps refreshed connection credentials when the grant mirror has advanced', function (): void {
    Log::spy();

    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create(['credential_version' => 3]);
    $ledger = app(StravaGrantLedger::class);
    $ledger->recordGrant(
        $connection->strava_athlete_id,
        $user->id,
        4,
        'newer-refresh',
        StravaGrantEventType::Reconnected,
    );

    expect($ledger->persistConnectionRefresh(
        $connection,
        3,
        'fresh-access',
        'fresh-refresh',
        Carbon::now()->addHours(6),
    ))->toBeTrue();

    expect($connection->fresh()->access_token)->toBe('fresh-access')
        ->and($connection->fresh()->refresh_token)->toBe('fresh-refresh')
        ->and(StravaGrantToken::query()->where('strava_athlete_id', $connection->strava_athlete_id)->sole()->refresh_token)
        ->toBe('newer-refresh');

    Log::shouldHaveReceived('warning')->once()->with(
        'Skipped mirroring a stale Strava refresh because a newer grant exists.',
        [
            'connection_id' => $connection->getKey(),
            'connection_credential_version' => 3,
            'grant_credential_version' => 4,
        ],
    );
});

it('distinguishes a grant mirror behind the connection when skipping a refresh', function (): void {
    Log::spy();

    $user = User::factory()->create();
    $connection = StravaConnection::factory()->for($user)->create(['credential_version' => 4]);
    $ledger = app(StravaGrantLedger::class);
    $ledger->recordGrant(
        $connection->strava_athlete_id,
        $user->id,
        3,
        'older-refresh',
        StravaGrantEventType::Granted,
    );

    expect($ledger->persistConnectionRefresh(
        $connection,
        4,
        'fresh-access',
        'fresh-refresh',
        Carbon::now()->addHours(6),
    ))->toBeTrue();

    expect($connection->fresh()->access_token)->toBe('fresh-access')
        ->and($connection->fresh()->refresh_token)->toBe('fresh-refresh')
        ->and(StravaGrantToken::query()->where('strava_athlete_id', $connection->strava_athlete_id)->sole()->refresh_token)
        ->toBe('older-refresh');

    Log::shouldHaveReceived('warning')->once()->with(
        'Skipped mirroring a Strava refresh because the grant mirror is behind the connection.',
        [
            'connection_id' => $connection->getKey(),
            'connection_credential_version' => 4,
            'grant_credential_version' => 3,
        ],
    );
});

it('does not record an old release outcome over a newer OAuth grant', function (): void {
    $ledger = app(StravaGrantLedger::class);
    $ledger->recordGrant(12345, 7, 4, 'old-refresh', StravaGrantEventType::Granted);
    $oldGrant = StravaGrantToken::query()->where('strava_athlete_id', 12345)->sole();
    $ledger->recordGrant(12345, 7, 5, 'new-refresh', StravaGrantEventType::Reconnected);

    $recorded = $ledger->recordReleaseOutcome(
        $oldGrant,
        new StravaGrantReleaseResult(StravaGrantReleaseStatus::Released),
        forced: true,
    );

    expect($recorded)->toBeFalse()
        ->and(StravaGrantToken::query()->where('strava_athlete_id', 12345)->sole()->refresh_token)->toBe('new-refresh')
        ->and(StravaGrantEvent::query()->where('strava_athlete_id', 12345)->orderBy('id')->get()->map(fn (StravaGrantEvent $event) => $event->event)->all())
        ->toBe([StravaGrantEventType::Granted, StravaGrantEventType::Reconnected]);
});

it('keeps release failures open and reports their attempts and last error', function (): void {
    $ledger = app(StravaGrantLedger::class);
    $ledger->recordGrant(12345, 7, 2, 'refresh-token', StravaGrantEventType::Granted);
    $grant = StravaGrantToken::query()->where('strava_athlete_id', 12345)->sole();
    $ledger->recordReleaseOutcome(
        $grant,
        new StravaGrantReleaseResult(StravaGrantReleaseStatus::Failed, 'Strava returned HTTP 503.'),
        forced: false,
    );

    $holder = $ledger->holderRows()->sole();

    expect($holder->strava_athlete_id)->toBe(12345)
        ->and($holder->release_attempts)->toBe(1)
        ->and($holder->last_error)->toBe('Strava returned HTTP 503.')
        ->and(StravaGrantToken::query()->where('strava_athlete_id', 12345)->exists())->toBeTrue();
});
