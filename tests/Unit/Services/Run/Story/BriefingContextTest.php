<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Story\BriefingContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('returns nulls when the user has no snapshots or activities', function (): void {
    $user = User::factory()->create();

    $ctx = BriefingContext::forUser($user, Carbon::create(2026, 5, 21, 8));

    expect($ctx->thisWeekRuns)->toBeNull()
        ->and($ctx->lastWeekRuns)->toBeNull()
        ->and($ctx->recoveryHours)->toBeNull()
        ->and($ctx->ranToday)->toBeFalse()
        ->and($ctx->daysSinceLastRun)->toBeNull()
        ->and($ctx->formStatus)->toBeNull()
        ->and($ctx->consecutiveWeeksActive)->toBe(0)
        ->and($ctx->fitnessTrend)->toBe('plateau')
        ->and($ctx->volumeRampPct)->toBeNull()
        // No form + no recovery data -> conservative moderate cap, no build nudge.
        ->and($ctx->readinessCeiling)->toBe('moderate_ok')
        ->and($ctx->buildNudge)->toBeFalse();
});

it('pulls this-week and last-week snapshots aligned to Sunday week_ending', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::create(2026, 5, 21, 8); // Thursday in week ending 2026-05-24 (Sun)

    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-24',
        'runs' => 4,
        'distance_km' => 32.0,
        'form_status' => 'optimal',
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17',
        'runs' => 2,
        'distance_km' => 15.0,
    ]);

    $ctx = BriefingContext::forUser($user, $asOf);

    expect($ctx->thisWeekRuns)->toBe(4)
        ->and($ctx->thisWeekKm)->toBe(32.0)
        ->and($ctx->lastWeekRuns)->toBe(2)
        ->and($ctx->lastWeekKm)->toBe(15.0)
        ->and($ctx->formStatus)->toBe('optimal');
});

it('computes recovery hours from the most recent activity start', function (): void {
    $asOf = Carbon::create(2026, 5, 21, 18);
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::create(2026, 5, 20, 6),
    ]);

    $ctx = BriefingContext::forUser($user, $asOf);

    expect($ctx->recoveryHours)->toBe(36)
        ->and($ctx->ranToday)->toBeFalse()
        ->and($ctx->daysSinceLastRun)->toBe(1);
});

it('reports null recovery on a run day so the narration cannot contradict the chip', function (): void {
    $asOf = Carbon::create(2026, 5, 21, 12);
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::create(2026, 5, 19, 7)]);
    $today = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($today)->create(['start_date_local' => Carbon::create(2026, 5, 21, 6)]);

    $ctx = BriefingContext::forUser($user, $asOf);

    expect($ctx->ranToday)->toBeTrue()
        ->and($ctx->recoveryHours)->toBeNull();
});

it('reads the CTL slope over recent snapshots as a fitness trend', function (array $ctlSeries, string $trend): void {
    $user = User::factory()->create();
    $asOf = Carbon::create(2026, 5, 21, 8); // week ending 2026-05-24
    $weekEnd = Carbon::parse('2026-05-24');
    foreach ($ctlSeries as $i => $ctl) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => $weekEnd->copy()->subWeeks(count($ctlSeries) - 1 - $i)->toDateString(),
            'runs' => 3,
            'ctl_42d' => $ctl,
        ]);
    }

    $ctx = BriefingContext::forUser($user, $asOf);

    expect($ctx->fitnessTrend)->toBe($trend);
})->with([
    'rising' => [[30.0, 33.0, 36.0, 40.0], 'naik'],
    'falling' => [[40.0, 36.0, 33.0, 30.0], 'turun'],
    'flat' => [[35.0, 35.2, 34.9, 35.1], 'plateau'],
]);

it('exposes a deterministic readiness ceiling from the live load, capping quality on a red flag', function (): void {
    $asOf = Carbon::create(2026, 5, 21, 8);
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();

    // Overreaching load is a hard red flag -> rest, regardless of anything else.
    $ctx = BriefingContext::forUser($user, $asOf, ['form_status' => 'overreaching', 'monotony' => 1.0]);

    expect($ctx->readinessCeiling)->toBe('rest');
});

it('falls back to last-week form_status when this week has no snapshot yet', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::create(2026, 5, 21, 8); // week ending 2026-05-24

    // Only the prior week has a snapshot; this week hasn't been aggregated yet.
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17',
        'form_status' => 'fatigued',
        'runs' => 2,
    ]);

    $ctx = BriefingContext::forUser($user, $asOf);

    expect($ctx->formStatus)->toBe('fatigued');
});

it('falls back to last-week form_status when this week has a snapshot but no form_status yet', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::create(2026, 5, 21, 8); // week ending 2026-05-24

    // This week is aggregated (has a runs count) but form_status hasn't been
    // computed into it yet — must still fall back to last week's, not stay null.
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-24',
        'runs' => 3,
        'form_status' => null,
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17',
        'form_status' => 'fatigued',
        'runs' => 2,
    ]);

    $ctx = BriefingContext::forUser($user, $asOf);

    expect($ctx->formStatus)->toBe('fatigued');
});

it('counts consecutive active weeks back from the current week', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::create(2026, 5, 21, 8); // week ending 2026-05-24

    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-24', 'runs' => 3]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10', 'runs' => 0]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03', 'runs' => 2]);

    $ctx = BriefingContext::forUser($user, $asOf);

    expect($ctx->consecutiveWeeksActive)->toBe(2);
});

it('buckets the hour-of-day into Indonesian-friendly labels', function (int $hour, string $bucket): void {
    $user = User::factory()->create();
    $ctx = BriefingContext::forUser($user, Carbon::create(2026, 5, 21, $hour));

    expect($ctx->timeBucket)->toBe($bucket);
})->with([
    'subuh 04:00' => [4, 'subuh'],
    'subuh 05:30' => [5, 'subuh'],
    'pagi 08:00' => [8, 'pagi'],
    'siang 12:00' => [12, 'siang'],
    'sore 17:00' => [17, 'sore'],
    'malam 21:00' => [21, 'malam'],
    'dini hari 02:00' => [2, 'malam'],
    'malam 03:00 (just before subuh)' => [3, 'malam'],
    'pagi 06:00 (subuh/pagi boundary)' => [6, 'pagi'],
    'pagi 10:00 (just before siang)' => [10, 'pagi'],
    'siang 11:00 (pagi/siang boundary)' => [11, 'siang'],
    'siang 14:00 (just before sore)' => [14, 'siang'],
    'sore 15:00 (siang/sore boundary)' => [15, 'sore'],
    'sore 18:00 (just before malam)' => [18, 'sore'],
    'malam 19:00 (sore/malam boundary)' => [19, 'malam'],
]);

it('serialises to a compact array suitable for the LLM user message', function (): void {
    $user = User::factory()->create();
    $ctx = BriefingContext::forUser($user, Carbon::create(2026, 5, 21, 8));

    expect($ctx->toArray())->toHaveKeys([
        'this_week_runs', 'last_week_runs', 'this_week_km', 'last_week_km',
        'recovery_hours', 'ran_today', 'days_since_last_run', 'form_status',
        'time_bucket', 'consecutive_weeks_active', 'fitness_trend',
        'volume_ramp_pct', 'readiness_ceiling', 'build_nudge',
    ]);
});
