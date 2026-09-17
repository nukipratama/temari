<?php

declare(strict_types=1);

use App\Enums\IntentVerdict;
use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\StoryLine;
use App\Services\AI\MaterialFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $detail
 */
function fingerprintActivity(array $detail = [], ?string $mood = null): Activity
{
    $activity = Activity::factory()->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(array_merge([
        'distance' => 5000.0,
        'moving_time' => 1500,
        'elapsed_time' => 1500,
        'average_heartrate' => 150.0,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 80, 'Z3' => 20], 'decoupling_pct' => 6.0],
    ], $detail));

    if ($mood !== null) {
        StoryLine::factory()->create([
            'activity_id' => $activity->id,
            'user_id' => $activity->user_id,
            'kind' => StoryLine::KIND_POST_RUN,
            'mood' => $mood,
        ]);
    }

    return $activity;
}

// Recompute over the current DB state — mutating one activity keeps every
// factory-randomised field constant, so only the field under test moves.
function fingerprint(int $activityId): string
{
    return MaterialFingerprint::forActivity(Activity::with('detail')->findOrFail($activityId));
}

it('is stable when nothing changes', function (): void {
    $activity = fingerprintActivity();

    expect(fingerprint($activity->id))->toBe(fingerprint($activity->id));
});

it('ignores sub-granularity jitter (distance within 10m, decoupling within 1%)', function (): void {
    $activity = fingerprintActivity(['distance' => 5000.0, 'stream_summary' => ['decoupling_pct' => 6.0]]);
    $before = fingerprint($activity->id);

    $activity->detail->update(['distance' => 5003.0, 'stream_summary' => ['decoupling_pct' => 6.3]]);

    expect(fingerprint($activity->id))->toBe($before);
});

it('changes when distance moves beyond the 10m bucket', function (): void {
    $activity = fingerprintActivity(['distance' => 5000.0]);
    $before = fingerprint($activity->id);

    $activity->detail->update(['distance' => 5200.0]);

    expect(fingerprint($activity->id))->not->toBe($before);
});

it('changes when decoupling shifts materially (6% to 14%)', function (): void {
    $activity = fingerprintActivity(['stream_summary' => ['decoupling_pct' => 6.0]]);
    $before = fingerprint($activity->id);

    $activity->detail->update(['stream_summary' => ['decoupling_pct' => 14.0]]);

    expect(fingerprint($activity->id))->not->toBe($before);
});

it('changes when a trailing partial split appears (so a resync re-narrates the finish)', function (): void {
    $activity = fingerprintActivity(['stream_summary' => ['decoupling_pct' => 6.0]]);
    $before = fingerprint($activity->id);

    $activity->detail->update(['stream_summary' => [
        'decoupling_pct' => 6.0,
        'partial_split' => ['distance_m' => 700, 'pace' => '5:30'],
    ]]);

    expect(fingerprint($activity->id))->not->toBe($before);
});

it('changes when the mood flips', function (): void {
    $activity = fingerprintActivity([], 'chill');
    $before = fingerprint($activity->id);

    StoryLine::query()->where('activity_id', $activity->id)->update(['mood' => 'blazing']);

    expect(fingerprint($activity->id))->not->toBe($before);
});

it('re-narrates a race day whose distance changed, and leaves every other row\'s fingerprint untouched', function (): void {
    $tenK = PlannedSession::factory()->make([
        'session_type' => SessionType::Race,
        'phase' => PlanPhase::Taper,
        'race_distance_m' => 10_000,
    ]);
    $half = PlannedSession::factory()->make([
        'session_type' => SessionType::Race,
        'phase' => PlanPhase::Taper,
        'race_distance_m' => 21_097,
    ]);

    expect(MaterialFingerprint::forPlannedSession($tenK, 16.0))
        ->not->toBe(MaterialFingerprint::forPlannedSession($half, 16.0));
});

it('leaves a non-race day fingerprinted exactly as it was before race days existed', function (): void {
    $session = PlannedSession::factory()->make([
        'session_type' => SessionType::Long,
        'phase' => PlanPhase::Build,
        'skipped' => false,
        'race_distance_m' => null,
    ]);

    // Pinned: the digest of the four keys a non-race row has always carried,
    // ksorted as MaterialFingerprint::digest() does. A fifth key here would
    // re-narrate every stored row on the next sweep.
    expect(MaterialFingerprint::forPlannedSession($session, 16.0))
        ->toBe(hash('xxh128', (string) json_encode([
            'long_run_km' => 16.0,
            'phase' => 'build',
            'session_type' => 'long',
            'skipped' => false,
        ])));
});

/**
 * An eased day's voice before credit is the clamp line, so the clamp adds no
 * key here: an untouched row keeps its digest, and easing a day re-bills nothing.
 */
it('keeps an eased day\'s digest identical to the untouched row\'s', function (): void {
    $untouched = PlannedSession::factory()->make([
        'session_type' => SessionType::Tempo,
        'phase' => PlanPhase::Build,
        'skipped' => false,
        'race_distance_m' => null,
    ]);
    $pinned = hash('xxh128', (string) json_encode([
        'long_run_km' => 16.0,
        'phase' => 'build',
        'session_type' => 'tempo',
        'skipped' => false,
    ]));

    expect(MaterialFingerprint::forPlannedSession($untouched, 16.0))->toBe($pinned)
        ->and(MaterialFingerprint::forPlannedSession($untouched->replicate()->forceFill(['clamped_km' => 3.6]), 16.0))->toBe($pinned)
        ->and(MaterialFingerprint::forPlannedSession($untouched->replicate()->forceFill(['rest_clamped_at' => now()]), 16.0))->toBe($pinned);
});

/**
 * The day flipping to credited turns the blurb from a label into a read of what
 * happened, so it has to re-narrate once — and exactly once.
 */
it('re-fingerprints a day once it is credited', function (): void {
    $planned = PlannedSession::factory()->make([
        'session_type' => SessionType::Tempo,
        'phase' => PlanPhase::Build,
        'skipped' => false,
        'race_distance_m' => null,
        'status' => PlannedSessionStatus::Planned,
    ]);
    $done = PlannedSession::factory()->make([
        'session_type' => SessionType::Tempo,
        'phase' => PlanPhase::Build,
        'skipped' => false,
        'race_distance_m' => null,
        'status' => PlannedSessionStatus::Done,
    ]);

    expect(MaterialFingerprint::forPlannedSession($planned, 16.0))
        ->not->toBe(MaterialFingerprint::forPlannedSession($done, 16.0));
});

/**
 * The score moves with every run that lands; the verdict is what changes the
 * sentence. Including the score would re-bill a second run on the same day.
 */
it('does not re-fingerprint a credited day when only its score moves', function (): void {
    $make = fn (int $score): PlannedSession => PlannedSession::factory()->make([
        'session_type' => SessionType::Tempo,
        'phase' => PlanPhase::Build,
        'skipped' => false,
        'race_distance_m' => null,
        'status' => PlannedSessionStatus::Done,
        'compliance_score' => $score,
    ]);

    expect(MaterialFingerprint::forPlannedSession($make(88), 16.0))
        ->toBe(MaterialFingerprint::forPlannedSession($make(126), 16.0));
});

/** Each verdict reads differently, so each has to be its own digest. */
it('separates the credited verdicts from one another', function (): void {
    $make = fn (PlannedSessionStatus $status): PlannedSession => PlannedSession::factory()->make([
        'session_type' => SessionType::Tempo,
        'phase' => PlanPhase::Build,
        'skipped' => false,
        'race_distance_m' => null,
        'status' => $status,
    ]);

    $digests = array_map(
        fn (PlannedSessionStatus $s): string => MaterialFingerprint::forPlannedSession($make($s), 16.0),
        [PlannedSessionStatus::Done, PlannedSessionStatus::Partial, PlannedSessionStatus::Overreached],
    );

    expect(array_unique($digests))->toHaveCount(3);
});

/**
 * A second run that flips the intent verdict (hit -> missed) changes what
 * the read says even when the distance status does not move, so the read
 * has to re-narrate on that too.
 */
it('re-fingerprints a credited day when only its intent verdict moves', function (): void {
    $make = fn (IntentVerdict $verdict): PlannedSession => PlannedSession::factory()->make([
        'session_type' => SessionType::Tempo,
        'phase' => PlanPhase::Build,
        'skipped' => false,
        'race_distance_m' => null,
        'status' => PlannedSessionStatus::Done,
        'intent_verdict' => $verdict,
    ]);

    expect(MaterialFingerprint::forPlannedSession($make(IntentVerdict::Hit), 16.0))
        ->not->toBe(MaterialFingerprint::forPlannedSession($make(IntentVerdict::Missed), 16.0));
});

/** An ungraded day's fingerprint never carries an intent verdict either. */
it('does not include the intent verdict on an ungraded day', function (): void {
    $planned = PlannedSession::factory()->make([
        'session_type' => SessionType::Tempo,
        'phase' => PlanPhase::Build,
        'skipped' => false,
        'race_distance_m' => null,
        'status' => PlannedSessionStatus::Planned,
        'intent_verdict' => null,
    ]);
    $sameButUnknownIntent = PlannedSession::factory()->make([
        'session_type' => SessionType::Tempo,
        'phase' => PlanPhase::Build,
        'skipped' => false,
        'race_distance_m' => null,
        'status' => PlannedSessionStatus::Planned,
        'intent_verdict' => IntentVerdict::Unknown,
    ]);

    expect(MaterialFingerprint::forPlannedSession($planned, 16.0))
        ->toBe(MaterialFingerprint::forPlannedSession($sameButUnknownIntent, 16.0));
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function trendTotals(array $overrides = []): array
{
    return array_merge([
        'range' => '30d',
        'current' => ['runs' => 5, 'distance_km' => 32.4, 'trimp_total' => 410.0],
        'comparison' => ['runs' => 4, 'distance_km' => 28.0, 'trimp_total' => 360.0],
        'ctl_start' => 38.0,
        'ctl_end' => 45.0,
        'vdot_start' => 42.0,
        'vdot_end' => 43.0,
        'avg_monotony' => 1.8,
        'avg_strain' => 620.0,
    ], $overrides);
}

it('is stable when the trend range totals do not change', function (): void {
    $totals = trendTotals();

    expect(MaterialFingerprint::forTrendRead($totals))->toBe(MaterialFingerprint::forTrendRead($totals));
});

it('ignores km jitter within the same 1-decimal bucket', function (): void {
    $before = trendTotals(['current' => ['runs' => 5, 'distance_km' => 32.41, 'trimp_total' => 410.0]]);
    $after = trendTotals(['current' => ['runs' => 5, 'distance_km' => 32.44, 'trimp_total' => 410.0]]);

    expect(MaterialFingerprint::forTrendRead($before))->toBe(MaterialFingerprint::forTrendRead($after));
});

it('changes when km moves beyond the 1-decimal bucket', function (): void {
    $before = trendTotals(['current' => ['runs' => 5, 'distance_km' => 32.3, 'trimp_total' => 410.0]]);
    $after = trendTotals(['current' => ['runs' => 5, 'distance_km' => 32.9, 'trimp_total' => 410.0]]);

    expect(MaterialFingerprint::forTrendRead($before))->not->toBe(MaterialFingerprint::forTrendRead($after));
});

it('changes when the run count moves', function (): void {
    $before = trendTotals(['current' => ['runs' => 5, 'distance_km' => 32.4, 'trimp_total' => 410.0]]);
    $after = trendTotals(['current' => ['runs' => 6, 'distance_km' => 32.4, 'trimp_total' => 410.0]]);

    expect(MaterialFingerprint::forTrendRead($before))->not->toBe(MaterialFingerprint::forTrendRead($after));
});

it('ignores sub-integer jitter on load, fitness and shape figures', function (): void {
    $before = trendTotals([
        'current' => ['runs' => 5, 'distance_km' => 32.4, 'trimp_total' => 410.2],
        'ctl_start' => 38.1,
        'ctl_end' => 45.4,
        'avg_monotony' => 1.8,
        'avg_strain' => 620.3,
    ]);
    $after = trendTotals([
        'current' => ['runs' => 5, 'distance_km' => 32.4, 'trimp_total' => 410.4],
        'ctl_start' => 38.4,
        'ctl_end' => 45.2,
        'avg_monotony' => 1.6,
        'avg_strain' => 620.4,
    ]);

    expect(MaterialFingerprint::forTrendRead($before))->toBe(MaterialFingerprint::forTrendRead($after));
});

it('changes when a load/fitness/shape figure crosses its whole-number bucket', function (): void {
    $before = trendTotals(['ctl_end' => 45.2]);
    $after = trendTotals(['ctl_end' => 46.1]);

    expect(MaterialFingerprint::forTrendRead($before))->not->toBe(MaterialFingerprint::forTrendRead($after));
});

it('keeps null figures null rather than bucketing them to zero', function (): void {
    $totals = trendTotals([
        'current' => ['runs' => 0, 'distance_km' => 0.0, 'trimp_total' => null],
        'ctl_start' => null,
        'ctl_end' => null,
        'vdot_start' => null,
        'vdot_end' => null,
        'avg_monotony' => null,
        'avg_strain' => null,
    ]);

    expect(MaterialFingerprint::forTrendRead($totals))
        ->not->toBe(MaterialFingerprint::forTrendRead(trendTotals(['current' => ['runs' => 0, 'distance_km' => 0.0, 'trimp_total' => 0.0]])));
});

it('is stable while the sustained-ahead signal has not flipped', function (): void {
    expect(MaterialFingerprint::forSeason(false))->toBe(MaterialFingerprint::forSeason(false));
});

it('changes the moment the sustained-ahead signal flips', function (): void {
    expect(MaterialFingerprint::forSeason(false))->not->toBe(MaterialFingerprint::forSeason(true));
});
