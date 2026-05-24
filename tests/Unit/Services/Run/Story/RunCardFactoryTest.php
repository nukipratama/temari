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

it('defaults to biasa rarity on a featureless short run', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 2_000,
        'stream_summary' => null,
        'weather_temp_c' => 25,
        'weather_rain_detected' => false,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->rarity)->toBe(Rarity::Common);
});

it('promotes to epik when this activity broke a PR', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 10_000,
        'stream_summary' => ['time_in_zone_pct' => ['Z3' => 40, 'Z4' => 30]],
    ]);
    PersonalRecord::factory()->for($activity->user)->create([
        'category' => '10km',
        'value_sec' => 3_300,
        'activity_id' => $activity->id,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->rarity)->toBe(Rarity::Epic);
});

it('promotes to legendaris on an all-time-longest half-marathon-plus', function (): void {
    $user = User::factory()->create();
    // Existing longest run for the user is 5km.
    $prevActivity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prevActivity)->create(['distance' => 5_000]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 21_500,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 80]],
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->rarity)->toBe(Rarity::Legendary);
});

it('promotes to langka on a 5K+ negative split (no PR)', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 8_000,
        'stream_summary' => ['negative_split' => true, 'time_in_zone_pct' => ['Z2' => 60, 'Z3' => 40]],
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->rarity)->toBe(Rarity::Rare);
});

it('awards the hari_panas badge when temp ≥ 31°C', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'weather_temp_c' => 32,
        'weather_rain_detected' => false,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('hari_panas');
});

it('awards pejuang_hujan badge on rain detection', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'weather_rain_detected' => true,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('pejuang_hujan');
});

it('awards anak_pagi badge when start hour is before 06:00', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-10 05:30:00'),
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('anak_pagi');
});

it('awards long_slow_distance badge on a 13km easy run > 1h', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 13_000,
        'elapsed_time' => 4_200,
        'stream_summary' => ['time_in_zone_pct' => ['Z1' => 10, 'Z2' => 80, 'Z3' => 10]],
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('long_slow_distance');
});

it('awards tahan_diri badge on a 10K+ run with <10% Z3+', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 10_000,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 95, 'Z3' => 5]],
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->badges)->toContain('tahan_diri');
});

it('skips the legendaris check when current detail has no distance', function (): void {
    $user = User::factory()->create();
    $prev = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($prev)->create(['distance' => 5_000]);

    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 0,
        'stream_summary' => null,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($card->rarity)->toBe(Rarity::Common);
});

it('is idempotent — rebuilding overwrites the same row', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'stream_summary' => ['negative_split' => false],
    ]);

    app(RunCardFactory::class)->build($activity, $detail);
    app(RunCardFactory::class)->build($activity, $detail);

    expect(RunCard::query()->where('activity_id', $activity->id)->count())->toBe(1);
});

it('queues a card reveal on the user when a fresh card is built', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'stream_summary' => null,
    ]);

    $card = app(RunCardFactory::class)->build($activity, $detail);

    expect($user->fresh()->pending_reveal_card_id)->toBe($card->id);
});

it('does not re-queue a reveal when rebuilding at the same rarity', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'stream_summary' => null,
    ]);

    app(RunCardFactory::class)->build($activity, $detail);
    $user->forceFill(['pending_reveal_card_id' => null])->save();
    app(RunCardFactory::class)->build($activity, $detail);

    expect($user->fresh()->pending_reveal_card_id)->toBeNull();
});

it('does not overwrite an existing pending reveal when a new card lands', function (): void {
    $user = User::factory()->create();

    $oldActivity = Activity::factory()->for($user)->create();
    $oldDetail = ActivityDetail::factory()->for($oldActivity)->create(['distance' => 4_000, 'stream_summary' => null]);
    $oldCard = app(RunCardFactory::class)->build($oldActivity, $oldDetail);

    // Second card while the first is still pending should NOT overwrite.
    $newActivity = Activity::factory()->for($user)->create();
    $newDetail = ActivityDetail::factory()->for($newActivity)->create(['distance' => 5_000, 'stream_summary' => null]);
    app(RunCardFactory::class)->build($newActivity, $newDetail);

    expect($user->fresh()->pending_reveal_card_id)->toBe($oldCard->id);
});
