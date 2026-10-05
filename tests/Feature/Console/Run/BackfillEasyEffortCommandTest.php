<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\RunnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>|null  $summary
 * @param  list<int>|null  $heartRate
 */
function backfillEasyEffortRun(User $user, ?array $summary, ?array $heartRate): ActivityDetail
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create(['stream_summary' => $summary]);
    $streams = ['time' => ['data' => range(0, 899)]];
    if ($heartRate !== null) {
        $streams['heartrate'] = ['data' => $heartRate];
    }
    ActivityStream::factory()->for($activity)->create(['data' => $streams]);

    return $detail;
}

it('writes the time over the easy cap under the athlete\'s current zones and leaves every other key alone', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create(['source' => 'manual']);
    $detail = backfillEasyEffortRun($user, ['gap_pace' => '6:00', 'time_in_zone_pct' => ['Z2' => 100]], array_fill(0, 900, 165));

    $this->artisan('run:backfill-easy-effort')->assertSuccessful();

    expect($detail->fresh()->stream_summary)->toEqual([
        'gap_pace' => '6:00',
        'time_in_zone_pct' => ['Z2' => 100],
        'easy_cap_bpm' => 154,
        'over_easy_cap_sec' => 599,
    ]);
});

it('is idempotent and replaces a stale figure on a re-run', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create(['source' => 'strava']);
    $detail = backfillEasyEffortRun($user, ['gap_pace' => '6:00', 'easy_cap_bpm' => 140, 'over_easy_cap_sec' => 5], array_fill(0, 900, 150));

    $this->artisan('run:backfill-easy-effort')->expectsOutputToContain('on 1 run(s), 0 already current')->assertSuccessful();
    $first = $detail->fresh()->stream_summary;
    $this->artisan('run:backfill-easy-effort')->expectsOutputToContain('on 0 run(s), 1 already current')->assertSuccessful();

    expect($first)->toEqual(['gap_pace' => '6:00', 'easy_cap_bpm' => 154, 'over_easy_cap_sec' => 0])
        ->and($detail->fresh()->stream_summary)->toBe($first);
});

it('drops the figure from a run whose stream carries no heart rate, and backfills the default-zone athlete too', function (): void {
    $noHeartRate = backfillEasyEffortRun(User::factory()->create(), ['gap_pace' => '6:00', 'easy_cap_bpm' => 154, 'over_easy_cap_sec' => 10], null);
    $defaultZones = backfillEasyEffortRun(User::factory()->create(), null, array_fill(0, 900, 170));

    $this->artisan('run:backfill-easy-effort', ['--chunk' => 1])->assertSuccessful();

    expect($noHeartRate->fresh()->stream_summary)->toBe(['gap_pace' => '6:00'])
        ->and($defaultZones->fresh()->stream_summary)->toMatchArray(['easy_cap_bpm' => config('runner.hr_zones.Z3.lo'), 'over_easy_cap_sec' => 599]);
});

it('limits the pass to one athlete when asked', function (): void {
    $chosen = User::factory()->create();
    $other = User::factory()->create();
    $chosenRun = backfillEasyEffortRun($chosen, null, array_fill(0, 900, 170));
    $otherRun = backfillEasyEffortRun($other, null, array_fill(0, 900, 170));

    $this->artisan('run:backfill-easy-effort', ['--user' => $chosen->id])->assertSuccessful();

    expect($chosenRun->fresh()->stream_summary)->toHaveKey('over_easy_cap_sec')
        ->and($otherRun->fresh()->stream_summary)->toBeNull();
});
