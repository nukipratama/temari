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

it('picks spinning mood on a hard session with ≥50% Z3+ time', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'stream_summary' => ['time_in_zone_pct' => ['Z2' => 20, 'Z3' => 50, 'Z4' => 30]],
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_MUMET);
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
        ->toBe(Temari::MOOD_LEMES);
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

it('picks bouncy mood when the run had a negative split', function (): void {
    $activity = Activity::factory()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 8_000,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z2' => 80, 'Z3' => 20],
            'negative_split' => true,
        ],
        'weather_temp_c' => 25,
    ]);

    expect(app(Temari::class)->postRunLine($activity, $detail)->mood)
        ->toBe(Temari::MOOD_ENTENG);
});
