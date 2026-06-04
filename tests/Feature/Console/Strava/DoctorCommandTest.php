<?php

declare(strict_types=1);

use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Default: skip the webhook self-handshake so no outbound HTTP fires unless
    // a test opts in by configuring the verify token.
    config(['services.strava.webhook_verify_token' => null]);
});

it('warns when no athlete is connected', function (): void {
    $this->artisan('strava:doctor')
        ->expectsOutputToContain('No users with a Strava connection found.')
        ->assertSuccessful();
});

it('reports the connection state for a connected athlete', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    Activity::factory()->for($user)->analyzed()->create();
    Activity::factory()->for($user)->create();

    $this->artisan('strava:doctor', ['--user' => $user->id])
        ->expectsOutputToContain('ok')
        ->assertSuccessful();
});

it('re-dispatches only stranded activities on --repair', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $stranded = Activity::factory()->for($user)->create(['analyzed_at' => null, 'detail_fail_count' => 0]);
    Activity::factory()->for($user)->analyzed()->create();                                   // done
    Activity::factory()->for($user)->create(['analyzed_at' => null, 'detail_fail_count' => 5]); // at the retry cap

    $this->artisan('strava:doctor', ['--repair' => true])->assertSuccessful();

    Bus::assertDispatchedTimes(IngestActivityJob::class, 1);
    Bus::assertDispatched(
        IngestActivityJob::class,
        fn (IngestActivityJob $job): bool => $job->activityId === $stranded->id,
    );
});

it('skips repair for a revoked connection', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->revoked()->create();
    Activity::factory()->for($user)->create(['analyzed_at' => null, 'detail_fail_count' => 0]);

    $this->artisan('strava:doctor', ['--repair' => true])->assertSuccessful();

    Bus::assertNotDispatched(IngestActivityJob::class);
});

it('passes the webhook self-handshake when the callback echoes the challenge', function (): void {
    config(['services.strava.webhook_verify_token' => 'verify-tok']);
    Http::fake([
        route('strava.webhook.verify').'*' => function ($request) {
            parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

            return Http::response(['hub.challenge' => $query['hub_challenge'] ?? '']);
        },
    ]);

    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $this->artisan('strava:doctor', ['--user' => $user->id])
        ->expectsOutputToContain('Webhook self-handshake: PASS')
        ->assertSuccessful();
});
