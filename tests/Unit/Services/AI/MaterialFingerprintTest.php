<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
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

it('changes when the mood flips', function (): void {
    $activity = fingerprintActivity([], 'adem');
    $before = fingerprint($activity->id);

    StoryLine::query()->where('activity_id', $activity->id)->update(['mood' => 'nyala']);

    expect(fingerprint($activity->id))->not->toBe($before);
});
