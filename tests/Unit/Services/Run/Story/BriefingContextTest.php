<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RecoveryFeedback;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\WeeklyAggregator;
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
        // No form + no recovery data is unknown, not a fatigue signal.
        ->and($ctx->readinessCeiling)->toBe('quality_ok')
        ->and($ctx->buildNudge)->toBeFalse();
});

it('pulls this-week snapshot and last-week real activity through the same weekday, aligned to Sunday week_ending', function (): void {
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
    // Last week (Mon 2026-05-11 - Sun 2026-05-17): a run on the Tuesday counts
    // toward "through Thursday", a run on the following Saturday does not.
    $tuesday = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($tuesday)->create([
        'start_date_local' => Carbon::create(2026, 5, 12, 7),
        'distance' => 8000.0,
    ]);
    $saturday = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($saturday)->create([
        'start_date_local' => Carbon::create(2026, 5, 16, 7),
        'distance' => 7000.0,
    ]);

    $ctx = BriefingContext::forUser($user, $asOf);

    expect($ctx->thisWeekRuns)->toBe(4)
        ->and($ctx->thisWeekKm)->toBe(32.0)
        ->and($ctx->lastWeekRuns)->toBe(1)
        ->and($ctx->lastWeekKm)->toBe(8.0)
        ->and($ctx->formStatus)->toBe('optimal');
});

it('does not compare a partial week against a full one, either in the numbers or the ramp', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::create(2026, 5, 19, 19); // Tuesday evening in week ending 2026-05-24 (Sun)

    // This week so far: 1 run, 5.3 km (Monday + Tuesday).
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-24',
        'runs' => 1,
        'distance_km' => 5.3,
    ]);
    // Last week's FULL total looks like a collapse against 5.3 km / 1 run,
    // but almost all of it happened after last week's own Tuesday.
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-17',
        'runs' => 3,
        'distance_km' => 17.4,
    ]);
    $mondayLastWeek = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($mondayLastWeek)->create([
        'start_date_local' => Carbon::create(2026, 5, 11, 7),
        'distance' => 5300.0,
    ]);
    $wednesdayLastWeek = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($wednesdayLastWeek)->create([
        'start_date_local' => Carbon::create(2026, 5, 13, 7),
        'distance' => 6000.0,
    ]);
    $saturdayLastWeek = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($saturdayLastWeek)->create([
        'start_date_local' => Carbon::create(2026, 5, 16, 7),
        'distance' => 6100.0,
    ]);

    $ctx = BriefingContext::forUser($user, $asOf);

    // Last week through Tuesday was also 1 run / 5.3 km: like-for-like, flat.
    expect($ctx->lastWeekRuns)->toBe(1)
        ->and($ctx->lastWeekKm)->toBe(5.3)
        ->and($ctx->volumeRampPct)->toBe(0.0)
        ->and($ctx->volumeRampPct)->not->toBeLessThan(-15.0)
        // Regression for #1009 (reopened): the LLM-facing shape carries no
        // bare signed volume_ramp_pct, just its own relation.
        ->and($ctx->toArray()['volume_ramp'])->toBe(['pct' => 0.0, 'relation' => 'flat']);
});

it('uses Sunday-ending calendar weeks for the current and matching prior ranges', function (
    string $asOfDate,
    string $thisWeekStart,
    string $lastWeekStart,
    string $lastWeekThrough,
    string $thisWeekEnd,
): void {
    $ranges = BriefingContext::weekToDateRanges(Carbon::parse($asOfDate, 'Asia/Jakarta'));

    expect($ranges['this_week_start']->toDateString())->toBe($thisWeekStart)
        ->and($ranges['last_week_start']->toDateString())->toBe($lastWeekStart)
        ->and($ranges['last_week_through']->toDateString())->toBe($lastWeekThrough)
        ->and($ranges['this_week_end']->toDateString())->toBe($thisWeekEnd);
})->with([
    'Monday' => ['2026-09-28', '2026-09-28', '2026-09-21', '2026-09-21', '2026-10-04'],
    'Sunday' => ['2026-10-04', '2026-09-28', '2026-09-21', '2026-09-27', '2026-10-04'],
    'month and year rollover' => ['2027-01-01', '2026-12-28', '2026-12-21', '2026-12-25', '2027-01-03'],
]);

// Regression for #1009 (reopened): a raw signed volume_ramp_pct used to reach
// the briefing narrator with no sign convention stated anywhere in the
// prompt. `volume_ramp` now carries the magnitude and its own relation, so
// there's no sign left for a narrator to invert.
it('exposes volume_ramp with relation=down and no signed field on a real drop in volume', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::create(2026, 5, 21, 8); // Thursday in week ending 2026-05-24 (Sun)

    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-24',
        'distance_km' => 10.0,
    ]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17']);
    $mondayLastWeek = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($mondayLastWeek)->create([
        'start_date_local' => Carbon::create(2026, 5, 11, 7),
        'distance' => 20_000.0, // last week through Thursday: 20 km, this week: 10 km, -50%
    ]);

    $ctx = BriefingContext::forUser($user, $asOf);

    $payload = $ctx->toArray();
    expect($payload['volume_ramp'])->toBe(['pct' => 50.0, 'relation' => 'down'])
        ->and($payload['readiness_assessment']['inputs'])->not->toHaveKey('volume_ramp_pct')
        ->and($payload['readiness_assessment']['inputs']['volume_ramp'])->toBe(['pct' => 50.0, 'relation' => 'down'])
        ->and($ctx->readinessAssessment['inputs']['volume_ramp_pct'])->toBe(-50.0);
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
    'rising' => [[30.0, 33.0, 36.0, 40.0], 'up'],
    'falling' => [[40.0, 36.0, 33.0, 30.0], 'down'],
    'flat' => [[35.0, 35.2, 34.9, 35.1], 'plateau'],
]);

it('does not infer rising fitness from weeks before the first HR reading', function (): void {
    $user = User::factory()->create();
    $asOf = Carbon::create(2026, 5, 21, 8);
    Carbon::setTestNow($asOf);
    foreach ([21 => null, 14 => null, 7 => null, 0 => 120.0] as $daysAgo => $trimp) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'trimp_edwards' => $trimp,
            'start_date_local' => $asOf->copy()->subDays($daysAgo),
        ]);
    }
    app(WeeklyAggregator::class)->rebuildFor($user);

    expect(BriefingContext::forUser($user, $asOf)->fitnessTrend)->toBe('plateau');
});

it('exposes a deterministic readiness ceiling from the live load, capping quality on a red flag', function (): void {
    $asOf = Carbon::create(2026, 5, 21, 8);
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();

    // Overreaching load is a hard red flag -> rest, regardless of anything else.
    $ctx = BriefingContext::forUser($user, $asOf, ['form_status' => 'overreaching', 'monotony' => 1.0]);

    expect($ctx->readinessCeiling)->toBe('rest');
});

it('uses current recovery feedback and actual demanding activity, not the latest run clock', function (): void {
    $asOf = Carbon::parse('2026-10-01 08:00');
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-09-30 12:00'),
        'elapsed_time' => 3600,
        'trimp_edwards' => 120.0,
        'stream_summary' => ['time_in_zone_min' => ['Z2' => 20, 'Z4' => 12]],
    ]);
    RecoveryFeedback::query()->create([
        'user_id' => $user->id,
        'date' => '2026-10-01',
        'sleep_quality' => 'good',
        'fatigue' => 'none',
        'soreness' => 'none',
        'concerning_pain' => false,
        'illness' => false,
    ]);

    $ctx = BriefingContext::forUser($user, $asOf, [
        'form_status' => 'optimal',
        'monotony' => 1.0,
    ]);

    expect($ctx->recoveryHours)->toBe(20)
        ->and($ctx->readinessCeiling)->toBe('moderate_ok')
        ->and($ctx->readinessAssessment['reasons'])->toBe(['demanding_session_within_24h'])
        ->and($ctx->readinessAssessment['inputs']['recent_training_stress']['sessions'][0]['demanding'])->toBeTrue()
        ->and($ctx->readinessAssessment['inputs']['recovery_feedback']['freshness'])->toBe('current');
});

it('does not restart demand spacing after an ordinary easy run or treat 45 hours as fatigue', function (): void {
    $asOf = Carbon::parse('2026-10-01 08:00');
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-09-30 20:00'),
        'elapsed_time' => 2400,
        'trimp_edwards' => 65.0,
        'stream_summary' => ['time_in_zone_min' => ['Z1' => 20, 'Z2' => 20]],
    ]);

    $ctx = BriefingContext::forUser($user, $asOf, [
        'form_status' => 'optimal',
        'monotony' => 1.0,
    ]);

    expect($ctx->recoveryHours)->toBe(12)
        ->and($ctx->readinessCeiling)->toBe('quality_ok')
        ->and($ctx->readinessAssessment['reasons'])->toBe([]);
});

it('records stale feedback but does not apply it to current readiness', function (): void {
    $asOf = Carbon::parse('2026-10-01 08:00');
    $user = User::factory()->create();
    RecoveryFeedback::query()->create([
        'user_id' => $user->id,
        'date' => '2026-09-28',
        'fatigue' => 'severe',
        'concerning_pain' => true,
        'illness' => true,
    ]);

    $ctx = BriefingContext::forUser($user, $asOf, ['form_status' => 'optimal', 'monotony' => 1.0]);

    expect($ctx->readinessCeiling)->toBe('quality_ok')
        ->and($ctx->readinessAssessment['reasons'])->toContain('stale_recovery_feedback_not_applied')
        ->and($ctx->readinessAssessment['inputs']['recovery_feedback']['freshness'])->toBe('stale');
});

it('keeps current pain and illness advice while training history is hydrating', function (): void {
    $asOf = Carbon::parse('2026-10-01 08:00');
    $user = User::factory()->create();
    RecoveryFeedback::query()->create([
        'user_id' => $user->id,
        'date' => '2026-10-01',
        'concerning_pain' => true,
        'illness' => false,
    ]);

    $ctx = BriefingContext::forUser($user, $asOf, null, historyLoading: true);

    expect($ctx->readinessCeiling)->toBe('rest')
        ->and($ctx->readinessAssessment['reasons'])->toContain('concerning_pain_reported')
        ->and($ctx->readinessAssessment['inputs']['recovery_feedback']['freshness'])->toBe('current')
        ->and($ctx->readinessAssessment['inputs']['recent_training_stress']['sessions'])->toBe([])
        ->and($ctx->readinessAssessment['inputs']['weekly_trimp'])->toBeNull();
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
    expect($ctx->toArray()['form_status'])->toBeNull()
        ->and($ctx->readinessAssessment['inputs']['form_status'])->toBeNull();
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

it('buckets the hour-of-day into time-of-day labels', function (int $hour, string $bucket): void {
    $user = User::factory()->create();
    $ctx = BriefingContext::forUser($user, Carbon::create(2026, 5, 21, $hour));

    expect($ctx->timeBucket)->toBe($bucket);
})->with([
    'early_morning 04:00' => [4, 'early_morning'],
    'early_morning 05:30' => [5, 'early_morning'],
    'morning 08:00' => [8, 'morning'],
    'midday 12:00' => [12, 'midday'],
    'evening 17:00' => [17, 'evening'],
    'night 21:00' => [21, 'night'],
    'dead of night 02:00' => [2, 'night'],
    'night 03:00 (just before early_morning)' => [3, 'night'],
    'morning 06:00 (early_morning/morning boundary)' => [6, 'morning'],
    'morning 10:00 (just before midday)' => [10, 'morning'],
    'midday 11:00 (morning/midday boundary)' => [11, 'midday'],
    'midday 14:00 (just before evening)' => [14, 'midday'],
    'evening 15:00 (midday/evening boundary)' => [15, 'evening'],
    'evening 18:00 (just before night)' => [18, 'evening'],
    'night 19:00 (evening/night boundary)' => [19, 'night'],
]);

it('serialises to a compact array suitable for the LLM user message', function (): void {
    $user = User::factory()->create();
    $ctx = BriefingContext::forUser($user, Carbon::create(2026, 5, 21, 8));

    expect($ctx->toArray())->toHaveKeys([
        'this_week_runs', 'last_week_runs', 'this_week_km', 'last_week_km',
        'recovery_hours', 'ran_today', 'days_since_last_run', 'form_status',
        'time_bucket', 'consecutive_weeks_active', 'fitness_trend',
        'volume_ramp', 'readiness_ceiling', 'build_nudge', 'readiness_reasons', 'readiness_assessment',
    ]);
});
