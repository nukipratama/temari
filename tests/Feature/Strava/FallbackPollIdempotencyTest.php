<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Run\Ingest\SyncOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::clear('strava-api:15min');
    RateLimiter::clear('strava-api:daily');
});

/**
 * The scheduled fallback poll is the scheduler's only ingest path. It must never
 * re-dispatch narration for activities it has already ingested: the fetcher
 * stops at the first activity it already knows about, so an already-analyzed
 * run is never handed back to the ingest pipeline, and no AI job (and no LLM
 * spend) fires for it.
 */
it('does not re-ingest or re-dispatch narration for an already-stored activity', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create([
        'access_token' => 'tok',
        'token_expires_at' => now()->addHours(2),
    ]);
    // Already synced + analyzed in a previous run.
    Activity::factory()->for($user)->create([
        'strava_external_id' => 777,
        'analyzed_at' => now()->subDay(),
    ]);

    Http::fake([
        // Strava returns the known activity first (newest-first) plus one older,
        // all of which are already-known or beyond the stop marker.
        'strava.com/api/v3/athlete/activities*' => Http::response([
            ['id' => 777, 'sport_type' => 'Run', 'start_date' => '2026-05-10T06:00:00Z'],
        ]),
    ]);

    $queued = app(SyncOrchestrator::class)->syncUser($user);

    expect($queued)->toBe(0);
    Bus::assertNotDispatched(IngestActivityJob::class);
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});
