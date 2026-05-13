<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\Run\Story\Temari;
use App\Services\Run\Story\VerdictTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-05-11 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function seedVerdict(User $user, Carbon $when, string $mood, string $speech, float $distanceMeters): void
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
        'speech' => $speech,
        'sigil_pattern' => 'dddd',
    ]);
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
            Temari::MOOD_BOUNCY,
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
        'mood' => Temari::MOOD_GLOW,
        'speech' => 'Pagi!',
        'sigil_pattern' => 'ssss',
    ]);
    seedVerdict($user, Carbon::today(), Temari::MOOD_BOUNCY, 'Real verdict', 5000.0);

    $items = app(VerdictTimeline::class)->recent($user);

    expect($items)->toHaveCount(1)
        ->and($items[0]->oneline)->toBe('Real verdict');
});

it('maps each mood to a face emoji', function (): void {
    $user = User::factory()->create();
    seedVerdict($user, Carbon::today(), Temari::MOOD_GLOW, 'glow', 5000.0);
    seedVerdict($user, Carbon::today()->subDay(), Temari::MOOD_BOUNCY, 'bouncy', 5000.0);
    seedVerdict($user, Carbon::today()->subDays(2), Temari::MOOD_WOBBLE, 'wobble', 5000.0);
    seedVerdict($user, Carbon::today()->subDays(3), Temari::MOOD_SQUISHED, 'squished', 5000.0);
    seedVerdict($user, Carbon::today()->subDays(4), Temari::MOOD_SPINNING, 'spinning', 5000.0);
    seedVerdict($user, Carbon::today()->subDays(5), Temari::MOOD_DIM, 'dim', 5000.0);

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
    // A real StoryLine with full data — should appear.
    seedVerdict($user, Carbon::today(), Temari::MOOD_BOUNCY, 'real verdict', 5000.0);
    // An orphaned StoryLine: activity exists but no ActivityDetail.
    $orphan = Activity::factory()->for($user)->create();
    StoryLine::query()->create([
        'user_id' => $user->id,
        'activity_id' => $orphan->id,
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => Temari::MOOD_DIM,
        'speech' => 'orphan verdict',
        'sigil_pattern' => 'dddd',
    ]);

    $items = app(VerdictTimeline::class)->recent($user);

    expect($items)->toHaveCount(1)
        ->and($items[0]->oneline)->toBe('real verdict');
});

it('converts meters to kilometers in the item DTO', function (): void {
    $user = User::factory()->create();
    seedVerdict($user, Carbon::today(), Temari::MOOD_BOUNCY, 'mantap', 7320.0);

    $items = app(VerdictTimeline::class)->recent($user);

    expect($items[0]->distanceKm)->toBe(7.3);
});

it('maps an unknown mood to the default rain face', function (): void {
    $user = User::factory()->create();
    seedVerdict($user, Carbon::today(), Temari::MOOD_DIM, 'dim verdict', 5000.0);

    $items = app(VerdictTimeline::class)->recent($user);

    expect($items)->toHaveCount(1)
        ->and($items[0]->moodFace)->toBe('🌧️');
});

it('does not leak verdicts across users', function (): void {
    $a = User::factory()->create();
    $b = User::factory()->create();
    seedVerdict($a, Carbon::today(), Temari::MOOD_BOUNCY, 'a only', 5000.0);

    expect(app(VerdictTimeline::class)->recent($b))->toBe([]);
});
