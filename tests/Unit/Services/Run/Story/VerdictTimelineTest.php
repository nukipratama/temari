<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\Temari;
use App\Services\Run\Story\VerdictTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-05-11 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function seedVerdict(User $user, Carbon $when, string $mood, ?string $speech, float $distanceMeters): Activity
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $when,
        'distance' => $distanceMeters,
    ]);
    StoryLine::query()->create([
        'user_id' => $user->id,
        'activity_id' => $activity->id,
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => $mood,
        'speech' => null,
        'sigil_pattern' => 'dddd',
    ]);

    if ($speech !== null) {
        Analysis::factory()->done($speech)->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => AnalysisType::PostRunSpeech,
            'discriminator' => null,
        ]);
    }

    return $activity;
}

it('returns an empty list when the user has no verdicts', function (): void {
    $user = User::factory()->create();

    expect(app(VerdictTimeline::class)->recent($user))->toBe([]);
});

it('orders verdicts newest-first and caps to the limit', function (): void {
    $user = User::factory()->create();
    foreach (range(0, 9) as $i) {
        seedVerdict(
            $user,
            Carbon::today()->subDays($i),
            Temari::MOOD_ENTENG,
            "Verdict day {$i}",
            5000.0 + $i,
        );
    }

    $items = app(VerdictTimeline::class)->recent($user, limit: 3);

    expect($items)->toHaveCount(3)
        ->and($items[0]->oneline)->toBe('Verdict day 0')
        ->and($items[1]->oneline)->toBe('Verdict day 1')
        ->and($items[2]->oneline)->toBe('Verdict day 2');
});

it('ignores daily-greeting story lines', function (): void {
    $user = User::factory()->create();
    StoryLine::query()->create([
        'user_id' => $user->id,
        'activity_id' => null,
        'for_date' => Carbon::today()->toDateString(),
        'kind' => StoryLine::KIND_DAILY_GREETING,
        'mood' => Temari::MOOD_NYALA,
        'speech' => null,
        'sigil_pattern' => 'ssss',
    ]);
    seedVerdict($user, Carbon::today(), Temari::MOOD_ENTENG, 'Real verdict', 5000.0);

    $items = app(VerdictTimeline::class)->recent($user);

    expect($items)->toHaveCount(1)
        ->and($items[0]->oneline)->toBe('Real verdict');
});

it('maps each mood to a face emoji', function (): void {
    $user = User::factory()->create();
    seedVerdict($user, Carbon::today(), Temari::MOOD_NYALA, 'glow', 5000.0);
    seedVerdict($user, Carbon::today()->subDay(), Temari::MOOD_ENTENG, 'bouncy', 5000.0);
    seedVerdict($user, Carbon::today()->subDays(2), Temari::MOOD_LEMES, 'wobble', 5000.0);
    seedVerdict($user, Carbon::today()->subDays(3), Temari::MOOD_OLENG, 'squished', 5000.0);
    seedVerdict($user, Carbon::today()->subDays(4), Temari::MOOD_MUMET, 'spinning', 5000.0);
    seedVerdict($user, Carbon::today()->subDays(5), Temari::MOOD_ADEM, 'dim', 5000.0);

    $items = app(VerdictTimeline::class)->recent($user);

    expect($items[0]->moodFace)->toBe('✨')
        ->and($items[1]->moodFace)->toBe('🦘')
        ->and($items[2]->moodFace)->toBe('🥵')
        ->and($items[3]->moodFace)->toBe('🍳')
        ->and($items[4]->moodFace)->toBe('💫')
        ->and($items[5]->moodFace)->toBe('🌧️');
});

it('skips story lines whose activity has no detail', function (): void {
    $user = User::factory()->create();
    seedVerdict($user, Carbon::today(), Temari::MOOD_ENTENG, 'real verdict', 5000.0);
    $orphan = Activity::factory()->for($user)->create();
    StoryLine::query()->create([
        'user_id' => $user->id,
        'activity_id' => $orphan->id,
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => Temari::MOOD_ADEM,
        'speech' => null,
        'sigil_pattern' => 'dddd',
    ]);
    Analysis::factory()->done('orphan verdict')->create([
        'subject_type' => Activity::class,
        'subject_id' => $orphan->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    $items = app(VerdictTimeline::class)->recent($user);

    expect($items)->toHaveCount(1)
        ->and($items[0]->oneline)->toBe('real verdict');
});

it('skips story lines whose LLM speech analysis is not yet done', function (): void {
    $user = User::factory()->create();
    seedVerdict($user, Carbon::today(), Temari::MOOD_ENTENG, 'done speech', 5000.0);
    $pending = seedVerdict($user, Carbon::today()->subDay(), Temari::MOOD_ADEM, null, 5000.0);
    Analysis::factory()->queued()->create([
        'subject_type' => Activity::class,
        'subject_id' => $pending->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    $items = app(VerdictTimeline::class)->recent($user);

    expect($items)->toHaveCount(1)
        ->and($items[0]->oneline)->toBe('done speech');
});

it('converts meters to kilometers in the item DTO', function (): void {
    $user = User::factory()->create();
    seedVerdict($user, Carbon::today(), Temari::MOOD_ENTENG, 'mantap', 7320.0);

    $items = app(VerdictTimeline::class)->recent($user);

    expect($items[0]->distanceKm)->toBe(7.3);
});

it('classifies session intensity from TRIMP density', function (float $trimp, int $movingTime, ?string $intensity): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'distance' => 6000.0,
        'trimp_edwards' => $trimp,
        'moving_time' => $movingTime,
    ]);
    StoryLine::query()->create([
        'user_id' => $user->id,
        'activity_id' => $activity->id,
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => Temari::MOOD_ENTENG,
        'speech' => null,
        'sigil_pattern' => 'dddd',
    ]);
    Analysis::factory()->done('verdict')->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    $items = app(VerdictTimeline::class)->recent($user);

    expect($items[0]->intensity)->toBe($intensity);
})->with([
    'easy: density 1.5' => [45.0, 1800, 'easy'],       // 45 / 30min = 1.5
    'moderate: density 2.5' => [75.0, 1800, 'moderate'], // 75 / 30min = 2.5
    'hard: density 3.5' => [105.0, 1800, 'hard'],        // 105 / 30min = 3.5
    'boundary 2.0 is moderate' => [60.0, 1800, 'moderate'], // exactly 2.0 -> not < 2.0
    'boundary 2.8 is moderate' => [84.0, 1800, 'moderate'], // exactly 2.8 -> <= 2.8
    'just over 2.8 is hard' => [84.6, 1800, 'hard'],         // 2.82 -> > 2.8
]);

it('returns a null intensity when TRIMP or moving time is missing', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'distance' => 6000.0,
        'trimp_edwards' => null,
        'moving_time' => 1800,
    ]);
    StoryLine::query()->create([
        'user_id' => $user->id,
        'activity_id' => $activity->id,
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => Temari::MOOD_ENTENG,
        'speech' => null,
        'sigil_pattern' => 'dddd',
    ]);
    Analysis::factory()->done('verdict')->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    $items = app(VerdictTimeline::class)->recent($user);

    expect($items[0]->intensity)->toBeNull();
});

it('maps an unknown mood to the default rain face', function (): void {
    $user = User::factory()->create();
    seedVerdict($user, Carbon::today(), Temari::MOOD_ADEM, 'dim verdict', 5000.0);

    $items = app(VerdictTimeline::class)->recent($user);

    expect($items)->toHaveCount(1)
        ->and($items[0]->moodFace)->toBe('🌧️');
});

it('does not leak verdicts across users', function (): void {
    $a = User::factory()->create();
    $b = User::factory()->create();
    seedVerdict($a, Carbon::today(), Temari::MOOD_ENTENG, 'a only', 5000.0);

    expect(app(VerdictTimeline::class)->recent($b))->toBe([]);
});

it('maps each mood to a face emoji via the private helper', function (string $mood, string $face): void {
    $method = new ReflectionMethod(VerdictTimeline::class, 'moodFace');

    expect($method->invoke(app(VerdictTimeline::class), $mood))->toBe($face);
})->with([
    [Temari::MOOD_NYALA, '✨'],
    [Temari::MOOD_ENTENG, '🦘'],
    [Temari::MOOD_LEMES, '🥵'],
    [Temari::MOOD_OLENG, '🍳'],
    [Temari::MOOD_MUMET, '💫'],
    [Temari::MOOD_ADEM, '🌧️'],
    ['unknown_mood', '🌧️'],
]);
