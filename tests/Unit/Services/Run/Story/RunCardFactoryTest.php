<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Models\RunCard;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\Story\RunCardFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('promotes to legendaris on a half-marathon PR with clean zone split', function (): void {
    $user = User::factory()->create();
    // Existing longest run for the user is 5km.
    $prevActivity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prevActivity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 21_500,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'elapsed_time' => 7_200,
        'stream_summary' => ['negative_split' => true, 'time_in_zone_pct' => ['Z2' => 90]],
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => 'half_marathon',
        'value_sec' => 6_300,
        'activity_id' => $activity->id,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    // Score: +3 PR, +2 negSplit, +2 longRun, +1 first-21K-bracket,
    // badges: negSplit, LSD(21.5K + Z3+=0 < 25%), held_back(Z3+ < 10%), z2_master(Z2=90 > 80), long_hauler(>=21K)
    // = 5 badges -> +5, zoneDiscipline=+1
    // Total: 3+2+2+1+5+1+0 = 14 -> Legendaris
    expect($card->rarity)->toBe(Rarity::Legendary);
});

it('is idempotent: rebuilding overwrites the same row', function (): void {
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'stream_summary' => ['negative_split' => false],
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    app(RunCardFactory::class)->build($activity, $detail);
    app(RunCardFactory::class)->build($activity, $detail);

    expect(RunCard::query()->where('activity_id', $activity->id)->count())->toBe(1);
});

it('does not downgrade a PR-minted card when a later run beats that PR on resync', function (): void {
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 8_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 12_500,
        'moving_time' => 4_500,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'elapsed_time' => 4_800,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 60, 'Z3' => 40]],
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 160,
        'max_heartrate' => 190,
    ]);
    $pr = PersonalRecord::factory()->for($user)->create([
        'category' => '10km',
        'value_sec' => 3_300,
        'activity_id' => $activity->id,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);
    expect($card->rarity)->toBe(Rarity::Rare)
        ->and($card->fresh()->pr_set)->toBeTrue();

    // A later, faster run reassigns the 10km PR to another activity.
    $faster = Activity::factory()->for($user)->analyzed()->create();
    $pr->update(['activity_id' => $faster->id, 'value_sec' => 3_000]);

    // Rebuilding the earlier card keeps the sticky +3 PR contribution and its tier.
    $rebuilt = app(RunCardFactory::class)->build($activity->fresh(), $detail->fresh());

    expect($rebuilt->rarity)->toBe(Rarity::Rare)
        ->and($rebuilt->pr_set)->toBeTrue();
});

it('does not claim a PR while an earlier run of the athlete is still awaiting hydration', function (): void {
    $user = User::factory()->create();
    $older = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($older)->create(['start_date_local' => Carbon::parse('2026-01-10 07:00:00')]);

    $activity = Activity::factory()->for($user)->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_190,
        'start_date_local' => Carbon::parse('2026-09-17 17:39:52'),
    ]);
    PersonalRecord::factory()->for($user)->create(['category' => '5km', 'activity_id' => $activity->id]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->pr_set)->toBeFalse()
        ->and($card->special_move)->not->toBe('Personal Best');
});

it('takes an explicit PR verdict over the sticky flag', function (): void {
    $activity = Activity::factory()->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse('2026-09-17 17:39:52')]);
    RunCard::factory()->create(['activity_id' => $activity->id, 'pr_set' => true]);

    expect(app(RunCardFactory::class)->build($activity, $detail, false)->pr_set)->toBeFalse()
        ->and(app(RunCardFactory::class)->build($activity, $detail, true)->pr_set)->toBeTrue();
});
