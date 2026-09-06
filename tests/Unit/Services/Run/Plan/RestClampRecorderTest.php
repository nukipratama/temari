<?php

declare(strict_types=1);

use App\Models\PlannedSession;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\RestClampRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** The readiness state that bottoms the ceiling out at Rest. */
function bottomOutReadiness(User $user): void
{
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'form_status' => 'overreaching',
        'monotony' => 1.0,
    ]);
}

function todaysSession(User $user, string $type = 'interval', bool $pinned = false): PlannedSession
{
    return PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => $type,
        'pinned' => $pinned,
    ]);
}

it('records a today the ceiling downgrades all the way to rest', function (): void {
    $user = User::factory()->create();
    bottomOutReadiness($user);
    $session = todaysSession($user);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeTrue()
        ->and($session->fresh()->rest_clamped_at)->not->toBeNull();
});

it('leaves a day the ceiling still permits alone', function (): void {
    $user = User::factory()->create();
    $session = todaysSession($user);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse()
        ->and($session->fresh()->rest_clamped_at)->toBeNull();
});

/** The render-time clamp exempts a pinned row, so nothing may excuse one here. */
it('never records against a pinned row', function (): void {
    $user = User::factory()->create();
    bottomOutReadiness($user);
    $session = todaysSession($user, pinned: true);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse()
        ->and($session->fresh()->rest_clamped_at)->toBeNull();
});

/**
 * Both the ingest listener and the daily briefing call this, and the listener
 * fires once per run — so a second call must not move the timestamp.
 */
it('writes once and keeps the original timestamp on a second call', function (): void {
    $user = User::factory()->create();
    bottomOutReadiness($user);
    $session = todaysSession($user);

    app(RestClampRecorder::class)->record($user, Carbon::today());
    $first = $session->fresh()->rest_clamped_at;

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse()
        ->and($session->fresh()->rest_clamped_at->equalTo($first))->toBeTrue();
});

it('does nothing for a user with no session today', function (): void {
    $user = User::factory()->create();
    bottomOutReadiness($user);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse();
});

it('scopes to the given user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    bottomOutReadiness($other);
    $session = todaysSession($other);

    expect(app(RestClampRecorder::class)->record($user, Carbon::today()))->toBeFalse()
        ->and($session->fresh()->rest_clamped_at)->toBeNull();
});
