<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Models\RunCard;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\RunnerProfile;
use App\Models\User;
use App\Services\Run\Story\RunCardFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('defaults to biasa rarity on a featureless short run', function (): void {
    // Seed a prior activity so first_timer does not trigger.
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-05-01 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 2_000,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'stream_summary' => null,
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->rarity)->toBe(Rarity::Common);
});

it('promotes to rare when this activity broke a PR on a long run', function (): void {
    // Seed a prior analyzed activity so first_timer and first-distance-bracket
    // do not inflate the score beyond what we assert.
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
    PersonalRecord::factory()->for($user)->create([
        'category' => '10km',
        'value_sec' => 3_300,
        'activity_id' => $activity->id,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    // Score: +3 PR, +2 long run (>=12km), +1 first-10K-bracket, +1 badge (all_out), +0 zone, +0 weekly = 7 -> Rare
    expect($card->rarity)->toBe(Rarity::Rare);
});

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

it('keeps a negative split with two badges at Common rather than Uncommon', function (): void {
    $user = User::factory()->create();
    // Prior analyzed activity at 6km so first_timer doesn't fire, and 8km won't be a new bracket.
    $prev = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 6_000,
        'moving_time' => 2_400,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 8_000,
        'moving_time' => 3_200,
        'elapsed_time' => 3_300,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'stream_summary' => ['negative_split' => true, 'time_in_zone_pct' => ['Z2' => 60, 'Z3' => 40]],
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 140,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    // Score: +2 negSplit, badges: negSplit + easy_miles (140bpm is 78% of the default
    // 180 max, an easy effort) -> badgeCount=2, = 2+2 = 4 -> Common.
    expect($card->rarity)->toBe(Rarity::Common);
});

it('awards the heat_tamer badge when temp >= 31C', function (): void {
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
        'weather_temp_c' => 32,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('heat_tamer');
});

it('awards climber on a short punchy climb even without big elevation gain', function (): void {
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
        'total_elevation_gain' => 40, // below the 200m gain threshold
        'stream_summary' => ['max_grade_pct' => 11.0],
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('climber');
});

it('withholds climber on a flat run with a gentle grade', function (): void {
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
        'total_elevation_gain' => 40,
        'stream_summary' => ['max_grade_pct' => 2.0],
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->not->toContain('climber');
});

it('awards rain_warrior badge on rain detection', function (): void {
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
        'weather_temp_c' => 25,
        'weather_rain_detected' => true,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('rain_warrior');
});

it('awards headwind badge when wind speed is 20 km/h or more', function (): void {
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
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'weather_wind_speed_kmh' => 20,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('headwind');
});

it('does not award headwind badge when wind speed is under 20 km/h', function (): void {
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
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'weather_wind_speed_kmh' => 19,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->not->toContain('headwind');
});

it('awards early_bird badge when start hour is before 06:00', function (): void {
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-10 05:30:00'),
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('early_bird');
});

it('awards long_slow_distance badge on a 13km easy run > 1h', function (): void {
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 13_000,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'elapsed_time' => 4_200,
        'stream_summary' => ['time_in_zone_pct' => ['Z1' => 10, 'Z2' => 80, 'Z3' => 10]],
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('long_slow_distance');
});

it('awards held_back badge on a 10K+ run with <10% Z3+', function (): void {
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 10_000,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 95, 'Z3' => 5]],
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('held_back');
});

it('skips the legendaris check when current detail has no distance', function (): void {
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 0,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'stream_summary' => null,
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->rarity)->toBe(Rarity::Common);
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

it('queues a card reveal on the user when a fresh card is built', function (): void {
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
        'stream_summary' => null,
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($user->fresh()->pending_reveal_card_id)->toBe($card->id);
});

it('does not re-queue a reveal when rebuilding at the same rarity', function (): void {
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
        'stream_summary' => null,
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    app(RunCardFactory::class)->build($activity, $detail);
    $user->forceFill(['pending_reveal_card_id' => null])->save();
    app(RunCardFactory::class)->build($activity, $detail);

    expect($user->fresh()->pending_reveal_card_id)->toBeNull();
});

it('does not overwrite an existing pending reveal when a new card lands', function (): void {
    $user = User::factory()->create();
    $seed = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($seed)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-01 10:00:00'),
    ]);

    $oldActivity = Activity::factory()->for($user)->create();
    $oldDetail = ActivityDetail::factory()->for($oldActivity)->create([
        'distance' => 4_000,
        'start_date_local' => Carbon::parse('2026-05-09 10:00:00'),
        'stream_summary' => null,
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);
    $oldCard = app(RunCardFactory::class)->build($oldActivity, $oldDetail);

    $newActivity = Activity::factory()->for($user)->create();
    $newDetail = ActivityDetail::factory()->for($newActivity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'stream_summary' => null,
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);
    app(RunCardFactory::class)->build($newActivity, $newDetail);

    expect($user->fresh()->pending_reveal_card_id)->toBe($oldCard->id);
});

// --- New badge tests ---

it('awards night_owl badge for a run before 5am', function (): void {
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-10 04:30:00'),
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('night_owl');
});

it('awards night_owl badge for a run after 9pm', function (): void {
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-10 21:30:00'),
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('night_owl');
});

it('awards climber badge on elevation gain >= 200m', function (): void {
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 8_000,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'total_elevation_gain' => 250,
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('climber');
});

it('awards first_timer badge on the very first run', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('first_timer');
});

it('still awards first_timer when an un-analyzed stub exists in the sync backlog', function (): void {
    $user = User::factory()->create();
    // A stub from an in-flight sync (no analyzed_at) must not suppress the badge
    // on the user's real first ingested run.
    Activity::factory()->for($user)->create(['analyzed_at' => null]);

    $activity = Activity::factory()->for($user)->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('first_timer');
});

it('awards speedster badge when pace is under 5:00/km', function (): void {
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    // 5km in 1400s = 280s/km = 4:40/km (under 5:00/km)
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'moving_time' => 1_400,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 175,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('speedster');
});

it('awards long_hauler badge on half marathon distance', function (): void {
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 10_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 21_500,
        'elapsed_time' => 7_200,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 60, 'Z3' => 40]],
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 160,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('long_hauler');
});

it('awards z2_master badge when Z2 > 80%', function (): void {
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 8_000,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 85, 'Z3' => 15]],
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('z2_master');
});

it('awards all_out badge when avg HR > 85% max', function (): void {
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
        'average_heartrate' => 170,
        'max_heartrate' => 190,
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('all_out');
});

it('awards easy_miles badge when avg HR < 70% max', function (): void {
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
        'average_heartrate' => 125,
        'max_heartrate' => 190,
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('easy_miles');
});

it('does not award all_out when avg HR is moderate against the athlete max HR', function (): void {
    // avg 130 against an athlete max of 190 is 0.68, comfortably easy. Under the
    // old run-peak denominator (130/150 = 0.87) this run was mislabeled all_out.
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create(['max_hr' => 190]);
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'average_heartrate' => 130,
        'max_heartrate' => 150,
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->not->toContain('all_out');
    expect($card->badges)->toContain('easy_miles');
});

it('awards all_out when avg HR is near the athlete max HR', function (): void {
    // avg 170 against an athlete max of 190 is 0.89, a genuinely hard effort.
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create(['max_hr' => 190]);
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'average_heartrate' => 170,
        'max_heartrate' => 185,
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('all_out');
    expect($card->badges)->not->toContain('easy_miles');
});

it('awards habit_forming badge on 3+ consecutive running days', function (): void {
    $user = User::factory()->create();
    ActivityDetail::factory()->for(Activity::factory()->for($user)->create())->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-05-07 10:00:00'),
    ]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->create())->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-05-08 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-09 10:00:00'),
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('habit_forming');
});

it('awards streak badge on 7+ consecutive running days', function (): void {
    $user = User::factory()->create();
    for ($i = 0; $i < 6; $i++) {
        ActivityDetail::factory()->for(Activity::factory()->for($user)->create())->create([
            'distance' => 3_000,
            'start_date_local' => Carbon::parse('2026-05-0' . ($i + 1) . ' 10:00:00'),
        ]);
    }

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-07 10:00:00'),
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('streak');
});

it('awards cold_runner on a cold run without also awarding early_bird', function (): void {
    // A cold midday run: cold_runner fires on temperature, early_bird does not
    // (hour is not before 06:00). The two badges now diverge.
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-10 15:00:00'),
        'weather_temp_c' => 14,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('cold_runner')
        ->and($card->badges)->not->toContain('early_bird');
});

it('awards early_bird on a warm pre-dawn run without also awarding cold_runner', function (): void {
    // The inverse divergence: an early but warm run earns early_bird only. Before
    // the fix both fired on the same hour<6 condition, double-awarding rarity.
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-10 05:30:00'),
        'weather_temp_c' => 27,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('early_bird')
        ->and($card->badges)->not->toContain('cold_runner');
});

it('falls back to a pre-dawn window for cold_runner when no weather is stored', function (): void {
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-10 04:30:00'),
        'weather_temp_c' => null,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('cold_runner');
});

// Badges stack with circumstance rather than merit: a hot, rainy, pre-dawn long
// run collects several without being remarkable. Uncapped they dominated the
// score and made Langka the most common tier of all, on half of every card.
it('caps how far the badge count alone can lift rarity', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create(['max_hr' => 200, 'resting_hr' => 50]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 6_000,
        'moving_time' => 2_400,
        'elapsed_time' => 2_400,
        // Pre-dawn, hot, wet, windy: five badges from circumstance alone.
        'start_date_local' => Carbon::parse('2026-05-10 04:30:00'),
        'weather_temp_c' => 33,
        'weather_rain_detected' => true,
        'weather_wind_speed_kmh' => 25,
        'average_heartrate' => 130,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 95]],
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect(count($card->badges))->toBeGreaterThan(3)
        ->and($card->rarity)->not->toBe(Rarity::Legendary)
        ->and($card->rarity)->not->toBe(Rarity::Epic);
});

// 70% of max is a recovery jog, not an easy run: on real data it awarded easy_miles
// to zero runs out of 136 while all_out took 69%.
it('awards easy_miles for a genuine easy effort rather than only a recovery jog', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create(['max_hr' => 190, 'resting_hr' => 55]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'moving_time' => 2_400,
        'elapsed_time' => 2_400,
        'start_date_local' => Carbon::parse('2026-05-10 10:00:00'),
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        // 143bpm is 75% of max: comfortably easy, but well above the old 70% bar.
        'average_heartrate' => 143,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('easy_miles')
        ->and($card->badges)->not->toContain('all_out');
});
