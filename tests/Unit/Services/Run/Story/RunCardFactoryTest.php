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
    // Seed a prior activity so pertama_kali does not trigger.
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

it('promotes to epik when this activity broke a PR on a long run', function (): void {
    // Seed a prior analyzed activity so pertama_kali and first-distance-bracket
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

    // Score: +3 PR, +2 long run (>=12km), +1 first-10K-bracket, +0 badges, +0 zone, +0 weekly = 6 -> Epic
    expect($card->rarity)->toBe(Rarity::Epic);
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
    // badges: negSplit, LSD(21.5K + Z3+=0 < 25%), tahan_diri(Z3+ < 10%), z2_master(Z2=90 > 80), jauh(>=21K)
    // = 5 badges -> +5, zoneDiscipline=+1
    // Total: 3+2+2+1+5+1+0 = 14 -> Legendaris
    expect($card->rarity)->toBe(Rarity::Legendary);
});

it('promotes to langka on a negative split with badges pushing score to 4-5', function (): void {
    $user = User::factory()->create();
    // Prior analyzed activity at 6km so pertama_kali doesn't fire, and 8km won't be a new bracket.
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

    // Score: +2 negSplit, badges: negSplit(1) -> badgeCount=1
    // = 0+2+0+0+1+0+0 = 3 -> Uncommon
    expect($card->rarity)->toBe(Rarity::Uncommon);
});

it('awards the hari_panas badge when temp >= 31C', function (): void {
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

    expect($card->badges)->toContain('hari_panas');
});

it('awards pejuang_hujan badge on rain detection', function (): void {
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

    expect($card->badges)->toContain('pejuang_hujan');
});

it('awards anak_pagi badge when start hour is before 06:00', function (): void {
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

    expect($card->badges)->toContain('anak_pagi');
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

it('awards tahan_diri badge on a 10K+ run with <10% Z3+', function (): void {
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

    expect($card->badges)->toContain('tahan_diri');
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

it('awards anak_malam badge for a run before 5am', function (): void {
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

    expect($card->badges)->toContain('anak_malam');
});

it('awards anak_malam badge for a run after 9pm', function (): void {
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

    expect($card->badges)->toContain('anak_malam');
});

it('awards pendaki badge on elevation gain >= 200m', function (): void {
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

    expect($card->badges)->toContain('pendaki');
});

it('awards pertama_kali badge on the very first run', function (): void {
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

    expect($card->badges)->toContain('pertama_kali');
});

it('still awards pertama_kali when an un-analyzed stub exists in the sync backlog', function (): void {
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

    expect($card->badges)->toContain('pertama_kali');
});

it('awards kilat badge when pace is under 5:00/km', function (): void {
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

    expect($card->badges)->toContain('kilat');
});

it('awards jauh badge on half marathon distance', function (): void {
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

    expect($card->badges)->toContain('jauh');
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

it('awards keras badge when avg HR > 85% max', function (): void {
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

    expect($card->badges)->toContain('keras');
});

it('awards santai badge when avg HR < 70% max', function (): void {
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

    expect($card->badges)->toContain('santai');
});

it('does not award keras when avg HR is moderate against the athlete max HR', function (): void {
    // avg 130 against an athlete max of 190 is 0.68, comfortably easy. Under the
    // old run-peak denominator (130/150 = 0.87) this run was mislabeled keras.
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

    expect($card->badges)->not->toContain('keras');
    expect($card->badges)->toContain('santai');
});

it('awards keras when avg HR is near the athlete max HR', function (): void {
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

    expect($card->badges)->toContain('keras');
    expect($card->badges)->not->toContain('santai');
});

it('awards rajin badge on 3+ consecutive running days', function (): void {
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

    expect($card->badges)->toContain('rajin');
});

it('awards berturut badge on 7+ consecutive running days', function (): void {
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

    expect($card->badges)->toContain('berturut');
});

it('awards hari_spesial badge on Indonesian Independence Day', function (): void {
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-08-17 10:00:00'),
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
        'total_elevation_gain' => 0,
        'average_heartrate' => 150,
        'max_heartrate' => 190,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('hari_spesial');
});

it('awards anak_dingin badge for a run before 6am', function (): void {
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

    expect($card->badges)->toContain('anak_dingin');
});

// --- Point-based scoring tests ---

it('maps score 0-1 to Biasa (Common)', function (): void {
    $factory = app(RunCardFactory::class);
    expect($factory->rarityFromScore(0))->toBe(Rarity::Common);
    expect($factory->rarityFromScore(1))->toBe(Rarity::Common);
});

it('maps score 2-3 to Berkesan (Uncommon)', function (): void {
    $factory = app(RunCardFactory::class);
    expect($factory->rarityFromScore(2))->toBe(Rarity::Uncommon);
    expect($factory->rarityFromScore(3))->toBe(Rarity::Uncommon);
});

it('maps score 4-5 to Langka (Rare)', function (): void {
    $factory = app(RunCardFactory::class);
    expect($factory->rarityFromScore(4))->toBe(Rarity::Rare);
    expect($factory->rarityFromScore(5))->toBe(Rarity::Rare);
});

it('maps score 6-7 to Istimewa (Epic)', function (): void {
    $factory = app(RunCardFactory::class);
    expect($factory->rarityFromScore(6))->toBe(Rarity::Epic);
    expect($factory->rarityFromScore(7))->toBe(Rarity::Epic);
});

it('maps score 8+ to Legendaris', function (): void {
    $factory = app(RunCardFactory::class);
    expect($factory->rarityFromScore(8))->toBe(Rarity::Legendary);
    expect($factory->rarityFromScore(20))->toBe(Rarity::Legendary);
});
