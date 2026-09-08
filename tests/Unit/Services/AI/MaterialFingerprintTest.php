<?php

declare(strict_types=1);

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
