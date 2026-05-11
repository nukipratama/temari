<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\Run\Story\Temari;
use App\Services\Run\Story\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('persists a post_run story line with mood + sigil + speech', function (): void {
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
        ->and($line->speech)->toBeString()->not->toBeEmpty()
        ->and($line->sigil_pattern)->toBeString()
        ->and($line->mood)->toBeIn([
            Temari::MOOD_BOUNCY,
            Temari::MOOD_GLOW,
            Temari::MOOD_WOBBLE,
            Temari::MOOD_DIM,
            Temari::MOOD_SPINNING,
            Temari::MOOD_SQUISHED,
        ]);
});

it('picks glow mood + PR celebration when this activity broke a PR', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 50, 'Z3' => 50]],
    ]);
    PersonalRecord::factory()->for($activity->user)->create([
        'category' => '5km',
        'activity_id' => $activity->id,
    ]);

    $line = app(Temari::class)->postRunLine($activity, $detail);

    expect($line->mood)->toBe(Temari::MOOD_GLOW)
        ->and($line->speech)->toContain('PR');
});

it('picks spinning mood on a hard session with ≥50% Z3+ time', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 20, 'Z3' => 50, 'Z4' => 30]],
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_SPINNING);
});

it('picks wobble mood when decoupling is high (>8%)', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 10_000,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z2' => 90, 'Z3' => 10],
            'decoupling_pct' => 12.0,
        ],
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_WOBBLE);
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
        ->toBe(Temari::MOOD_SQUISHED);
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

it('emits a daily greeting story line per (user, date)', function (): void {
    $user = User::factory()->create();
    $line = app(Temari::class)->dailyGreeting($user, Vibe::PUMPED, Carbon::parse('2026-05-11'));

    expect($line->kind)->toBe(StoryLine::KIND_DAILY_GREETING)
        ->and($line->activity_id)->toBeNull()
        ->and($line->for_date->toDateString())->toBe('2026-05-11')
        ->and($line->speech)->toContain('Membara'); // PUMPED label
});

it('upserts the daily greeting (no dup on second call)', function (): void {
    $user = User::factory()->create();
    app(Temari::class)->dailyGreeting($user, Vibe::PUMPED, Carbon::parse('2026-05-11'));
    app(Temari::class)->dailyGreeting($user, Vibe::FRESH, Carbon::parse('2026-05-11'));

    $rows = StoryLine::query()
        ->where('user_id', $user->id)
        ->where('for_date', '2026-05-11')
        ->get();

    expect($rows)->toHaveCount(1)->and($rows->first()->speech)->toContain('Segar');
});

it('produces a daily greeting with a non-empty speech for every vibe state', function (): void {
    $user = User::factory()->create();
    $vibes = [
        Vibe::PUMPED, Vibe::FRESH, Vibe::BOUNCY, Vibe::STEADY,
        Vibe::WORN_DOWN, Vibe::COOKED, Vibe::STRETCHED_THIN, Vibe::HIBERNATING,
    ];
    foreach ($vibes as $i => $vibe) {
        $line = app(Temari::class)->dailyGreeting($user, $vibe, Carbon::parse('2026-05-01')->addDays($i));
        expect($line->speech)->not->toBeEmpty()->and($line->mood)->not->toBeEmpty();
    }
});

it('renders a bouncy mood post-run line when the run had a negative split', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 8_000,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z2' => 80, 'Z3' => 20],
            'negative_split' => true,
        ],
        'weather_temp_c' => 25,
    ]);

    $line = app(Temari::class)->postRunLine($activity, $detail);

    expect($line->mood)->toBe(Temari::MOOD_BOUNCY);
});

it('produces deterministic speech for the same activity + mood pair', function (): void {
    $temari = new Temari();
    $first = $temari->generateSpeech(Temari::MOOD_GLOW, ['distance_km' => 5.0, 'dominant_zone' => 'Z3']);
    $second = $temari->generateSpeech(Temari::MOOD_GLOW, ['distance_km' => 5.0, 'dominant_zone' => 'Z3']);

    expect($first)->toBe($second);
});
