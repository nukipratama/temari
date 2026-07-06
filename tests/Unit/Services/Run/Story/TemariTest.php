<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeDailyGreetingJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\Temari;
use App\Services\Run\Story\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
});

it('persists a post_run story line with mood + sigil + null speech (LLM async)', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 10_000,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z2' => 80, 'Z3' => 20],
            'decoupling_pct' => 1.0,
        ],
        'weather_temp_c' => 27,
        'weather_rain_detected' => false,
    ]);

    $line = app(Temari::class)->postRunLine($activity, $detail);

    expect($line)->toBeInstanceOf(StoryLine::class)
        ->and($line->kind)->toBe(StoryLine::KIND_POST_RUN)
        ->and($line->activity_id)->toBe($activity->id)
        ->and($line->user_id)->toBe($activity->user_id)
        ->and($line->speech)->toBeNull()
        ->and($line->sigil_pattern)->toBeString()
        ->and($line->mood)->toBeIn([
            Temari::MOOD_ENTENG,
            Temari::MOOD_NYALA,
            Temari::MOOD_LEMES,
            Temari::MOOD_ADEM,
            Temari::MOOD_MUMET,
            Temari::MOOD_OLENG,
        ]);

    // Temari no longer dispatches the post-run analysis — ActivityPipeline's
    // cascadeAfterIngest owns that, tested separately.
    expect(Analysis::query()->count())->toBe(0);
});

it('picks glow mood when this activity broke a PR', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 50, 'Z3' => 50]],
    ]);
    PersonalRecord::factory()->for($activity->user)->create([
        'category' => '5km',
        'activity_id' => $activity->id,
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_NYALA);
});

it('picks mumet mood on a hard grind (≥80% Z3+ time) without a controlled finish', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 20, 'Z3' => 50, 'Z4' => 30]],
        'weather_temp_c' => 25,
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_MUMET);
});

it('picks lemes mood when decoupling is high (>12%)', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 10_000,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z2' => 90, 'Z3' => 10],
            'decoupling_pct' => 15.0,
        ],
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_LEMES);
});

it('does not flag decoupling at exactly 12% as lemes (boundary is strictly above)', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 10_000,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z2' => 90, 'Z3' => 10],
            'decoupling_pct' => 12.0,
        ],
        'weather_temp_c' => 25,
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_ADEM);
});

it('flags decoupling at 12.01% as lemes', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 10_000,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z2' => 90, 'Z3' => 10],
            'decoupling_pct' => 12.01,
        ],
        'weather_temp_c' => 25,
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_LEMES);
});

it('picks nyala for a hard session finished under control (neg split, low decoupling)', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 10_000,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z3' => 50, 'Z4' => 35],
            'negative_split' => true,
            'decoupling_pct' => 3.0,
        ],
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_NYALA);
});

it('picks nyala when decoupling is exactly at the 5% control ceiling', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 10_000,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z3' => 50, 'Z4' => 35],
            'negative_split' => true,
            'decoupling_pct' => 5.0,
        ],
        'weather_temp_c' => 25,
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_NYALA);
});

it('drops to enteng once decoupling is just past the 5% control ceiling', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 10_000,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z3' => 50, 'Z4' => 35],
            'negative_split' => true,
            'decoupling_pct' => 5.01,
        ],
        'weather_temp_c' => 25,
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_ENTENG);
});

it('picks enteng for a hard session that was controlled but not clean enough for nyala', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 10_000,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z3' => 50, 'Z4' => 35],
            'negative_split' => true,
            'decoupling_pct' => 9.0,
        ],
        'weather_temp_c' => 25,
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_ENTENG);
});

it('no longer flags a moderately hard run (50% hard zone) as mumet', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 10_000,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z2' => 50, 'Z3' => 30, 'Z4' => 20],
            'negative_split' => false,
        ],
        'weather_temp_c' => 25,
    ]);

    // hardShare 50 is under the raised 80 threshold, so this reads calm, not overreaching.
    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_ADEM);
});

it('picks squished mood on hot-weather easy runs', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 8_000,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 95]],
        'weather_temp_c' => 32,
        'weather_rain_detected' => false,
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_OLENG);
});

it('flags exactly 31°C as hot weather (oleng)', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 8_000,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 95]],
        'weather_temp_c' => 31,
        'weather_rain_detected' => false,
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_OLENG);
});

it('does not flag 30°C as hot weather', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 8_000,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 95]],
        'weather_temp_c' => 30,
        'weather_rain_detected' => false,
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_ADEM);
});

it('is idempotent — calling twice for the same activity updates the row', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 90]],
    ]);

    app(Temari::class)->postRunLine($activity, $detail);
    app(Temari::class)->postRunLine($activity, $detail);

    expect(StoryLine::query()->where('activity_id', $activity->id)->count())->toBe(1);
});

it('emits a daily greeting story line per (user, date) with null speech and dispatches NO LLM job', function (): void {
    $user = User::factory()->create();
    $line = app(Temari::class)->dailyGreeting($user, Vibe::PUMPED, Carbon::parse('2026-05-11'));

    expect($line->kind)->toBe(StoryLine::KIND_DAILY_GREETING)
        ->and($line->activity_id)->toBeNull()
        ->and($line->for_date->toDateString())->toBe('2026-05-11')
        ->and($line->speech)->toBeNull();

    // No LLM dispatch on page-load greeting — analyses are user-triggered.
    Bus::assertNotDispatched(AnalyzeDailyGreetingJob::class);

    $analysis = Analysis::query()
        ->forSubject(AnalysisType::DAILY_GREETING_SUBJECT_TYPE, $user->id, AnalysisType::DailyGreeting, '2026-05-11')
        ->first();
    expect($analysis)->toBeNull();
});

it('upserts the daily greeting (no dup on second call)', function (): void {
    $user = User::factory()->create();
    app(Temari::class)->dailyGreeting($user, Vibe::PUMPED, Carbon::parse('2026-05-11'));
    app(Temari::class)->dailyGreeting($user, Vibe::FRESH, Carbon::parse('2026-05-11'));

    expect(StoryLine::query()
        ->where('user_id', $user->id)
        ->where('for_date', '2026-05-11')
        ->count())->toBe(1);
});

it('maps each mood to its public accessory token', function (): void {
    expect(Temari::accessoryForMoodPublic(Temari::MOOD_NYALA))->toBe('headband')
        ->and(Temari::accessoryForMoodPublic(Temari::MOOD_ENTENG))->toBeNull()
        ->and(Temari::accessoryForMoodPublic(Temari::MOOD_ADEM))->toBe('mata-ngantuk')
        ->and(Temari::accessoryForMoodPublic(Temari::MOOD_LEMES))->toBeNull()
        ->and(Temari::accessoryForMoodPublic(Temari::MOOD_MUMET))->toBeNull()
        ->and(Temari::accessoryForMoodPublic(Temari::MOOD_OLENG))->toBeNull();
});

it('maps each vibe to a mood', function (): void {
    $temari = app(Temari::class);

    expect($temari->moodForVibe(Vibe::PUMPED))->toBe(Temari::MOOD_NYALA)
        ->and($temari->moodForVibe(Vibe::FRESH))->toBe(Temari::MOOD_NYALA)
        ->and($temari->moodForVibe(Vibe::BOUNCY))->toBe(Temari::MOOD_ENTENG)
        ->and($temari->moodForVibe(Vibe::WORN_DOWN))->toBe(Temari::MOOD_LEMES)
        ->and($temari->moodForVibe(Vibe::COOKED))->toBe(Temari::MOOD_OLENG)
        ->and($temari->moodForVibe(Vibe::STRETCHED_THIN))->toBe(Temari::MOOD_MUMET)
        ->and($temari->moodForVibe(Vibe::HIBERNATING))->toBe(Temari::MOOD_ADEM)
        ->and($temari->moodForVibe('unknown'))->toBe(Temari::MOOD_ADEM);
});

it('moodForActivityOrDefault matches the mood the post-run line would persist', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 8_000,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z2' => 80, 'Z3' => 20],
            'negative_split' => true,
        ],
        'weather_temp_c' => 25,
    ]);

    $activity->setRelation('detail', $detail);

    expect(Temari::moodForActivityOrDefault($activity))
        ->toBe(Temari::MOOD_ENTENG)
        ->toBe(app(Temari::class)->postRunLine($activity, $detail)->mood);
});

it('moodForActivityOrDefault falls back to adem when the activity has no detail', function (): void {
    // Returns before hasPr()'s query, so the activity never needs to be persisted.
    // user_id is pinned so the factory doesn't fall through to its
    // `User::factory()` default, which ->create()s a real User row even
    // under ->make() (nested belongsTo factory attributes always persist).
    $activity = Activity::factory()->make(['id' => 1, 'user_id' => 1]);
    $activity->setRelation('detail', null);

    expect(Temari::moodForActivityOrDefault($activity))->toBe(Temari::MOOD_ADEM);
});
