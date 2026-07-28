<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\Run\Story\MoodMix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function moodLine(User $user, string $mood, Carbon $when, bool $withActivity = true): StoryLine
{
    $activity = $withActivity ? Activity::factory()->for($user)->analyzed()->create() : null;

    $line = StoryLine::query()->create([
        'user_id' => $user->id,
        'activity_id' => $activity?->id,
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => $mood,
        'speech' => null,
        'sigil_pattern' => 'dddd',
    ]);

    // created_at is not fillable, so it has to be set after the insert.
    $line->created_at = $when;
    $line->save();

    return $line;
}

it('reads an empty mix when the runner has no story lines', function (): void {
    $user = User::factory()->create();

    expect(MoodMix::between($user->id, Carbon::parse('2026-01-01')))->toBe([]);
});

it('counts each mood and orders them by count descending', function (): void {
    $user = User::factory()->create();
    $when = Carbon::parse('2026-05-10 06:00:00');

    moodLine($user, 'adem', $when);
    moodLine($user, 'nyala', $when);
    moodLine($user, 'nyala', $when);
    moodLine($user, 'nyala', $when);

    expect(MoodMix::between($user->id, Carbon::parse('2026-05-01')))->toBe([
        ['mood' => 'nyala', 'count' => 3, 'percent' => 75.0],
        ['mood' => 'adem', 'count' => 1, 'percent' => 25.0],
    ]);
});

it('ignores story lines with no activity behind them', function (): void {
    $user = User::factory()->create();
    $when = Carbon::parse('2026-05-10 06:00:00');

    moodLine($user, 'nyala', $when);
    moodLine($user, 'lemes', $when, withActivity: false);

    expect(MoodMix::between($user->id, Carbon::parse('2026-05-01')))
        ->toBe([['mood' => 'nyala', 'count' => 1, 'percent' => 100.0]]);
});

it('does not leak one runner\'s moods into another\'s mix', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $when = Carbon::parse('2026-05-10 06:00:00');

    moodLine($mine, 'nyala', $when);
    moodLine($theirs, 'lemes', $when);

    expect(MoodMix::between($mine->id, Carbon::parse('2026-05-01')))
        ->toBe([['mood' => 'nyala', 'count' => 1, 'percent' => 100.0]]);
});

// Half-open [from, to): adjacent windows must tile without counting a run twice
// on the seam. This is the contract callers holding an inclusive end (an
// endOfMonth(), say) have to convert for.
it('includes a run at the exact start of the window and excludes one at the exact end', function (): void {
    $user = User::factory()->create();
    $from = Carbon::parse('2026-05-01 00:00:00');
    $to = Carbon::parse('2026-06-01 00:00:00');

    moodLine($user, 'nyala', $from);
    moodLine($user, 'lemes', $to);

    expect(MoodMix::between($user->id, $from, $to))
        ->toBe([['mood' => 'nyala', 'count' => 1, 'percent' => 100.0]]);
});

it('leaves the window open-ended when no upper bound is given', function (): void {
    $user = User::factory()->create();
    $from = Carbon::parse('2026-05-01 00:00:00');

    moodLine($user, 'nyala', $from);
    moodLine($user, 'enteng', Carbon::parse('2030-01-01 00:00:00'));

    expect(MoodMix::between($user->id, $from))->toHaveCount(2);
});

it('excludes runs before the window starts', function (): void {
    $user = User::factory()->create();

    moodLine($user, 'nyala', Carbon::parse('2026-04-30 23:59:59'));

    expect(MoodMix::between($user->id, Carbon::parse('2026-05-01 00:00:00')))->toBe([]);
});

// ── merge ────────────────────────────────────────────────────────────

it('folds two mixes into one with shares recomputed against the combined total', function (): void {
    $recent = [['mood' => 'nyala', 'count' => 2, 'percent' => 100.0]];
    $earlier = [
        ['mood' => 'adem', 'count' => 1, 'percent' => 50.0],
        ['mood' => 'nyala', 'count' => 1, 'percent' => 50.0],
    ];

    expect(MoodMix::merge($recent, $earlier))->toBe([
        ['mood' => 'nyala', 'count' => 3, 'percent' => 75.0],
        ['mood' => 'adem', 'count' => 1, 'percent' => 25.0],
    ]);
});

it('folds to an empty mix when every half is empty', function (): void {
    expect(MoodMix::merge([], []))->toBe([]);
});

// The fold exists so a caller with both halves can skip a third query for the
// window they add up to. It only earns that if it returns the same thing.
it('folds to exactly what querying the whole window would have returned', function (): void {
    $user = User::factory()->create();
    $windowStart = Carbon::parse('2026-03-01 00:00:00');
    $halfway = Carbon::parse('2026-04-15 00:00:00');

    moodLine($user, 'adem', Carbon::parse('2026-03-10 06:00:00'));
    moodLine($user, 'nyala', Carbon::parse('2026-03-20 06:00:00'));
    moodLine($user, 'nyala', Carbon::parse('2026-05-01 06:00:00'));

    $folded = MoodMix::merge(
        MoodMix::between($user->id, $halfway),
        MoodMix::between($user->id, $windowStart, $halfway),
    );

    expect($folded)->toBe(MoodMix::between($user->id, $windowStart));
});
