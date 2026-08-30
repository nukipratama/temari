<?php

declare(strict_types=1);

use App\Http\Requests\FeedFilterRequest;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\FeedFilters;
use App\Services\Run\FeedQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function feedFiltersFor(User $user, string $query = ''): FeedFilters
{
    return app(FeedQuery::class)->filtersFor(
        $user,
        FeedFilterRequest::create('/history'.($query === '' ? '' : '?'.$query)),
    );
}

/** @return array<int, string> */
function feedRunNames(User $user, string $query = ''): array
{
    $feed = app(FeedQuery::class);

    return $feed->for($user, feedFiltersFor($user, $query))
        ->get()
        ->map(fn (Activity $run): string => (string) $run->detail?->name)
        ->all();
}

function feedRun(User $user, string $name, Carbon $date, ?float $distance = null, ?int $elapsedTime = null): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'name' => $name,
        'start_date_local' => $date,
        ...($distance === null ? [] : ['distance' => $distance]),
        ...($elapsedTime === null ? [] : ['elapsed_time' => $elapsedTime]),
    ]);

    return $activity;
}

it('keeps the requested range when it already reaches the newest run', function (): void {
    $user = User::factory()->create();
    feedRun($user, 'Recent', Carbon::now()->subDays(3));

    $filters = feedFiltersFor($user);

    expect($filters->range)->toBe('8w')
        ->and($filters->rangeAutoWidened)->toBeFalse()
        ->and($filters->rangeStart?->toDateString())->toBe(Carbon::today()->subDays(55)->toDateString());
});

it('keeps the requested range when the user has no runs at all', function (): void {
    $filters = feedFiltersFor(User::factory()->create());

    expect($filters->range)->toBe('8w')
        ->and($filters->rangeAutoWidened)->toBeFalse();
});

it('widens to the smallest preset that reaches the newest run', function (int $daysAgo, string $expected): void {
    $user = User::factory()->create();
    feedRun($user, 'Old', Carbon::now()->subDays($daysAgo));

    $filters = feedFiltersFor($user);

    expect($filters->range)->toBe($expected)
        ->and($filters->rangeAutoWidened)->toBeTrue();
})->with([
    [70, '12w'],
    [150, '6m'],
    [300, '1y'],
    [900, 'all'],
]);

it('drops the lower bound entirely for the all range', function (): void {
    $user = User::factory()->create();
    feedRun($user, 'Ancient', Carbon::now()->subDays(900));

    expect(feedFiltersFor($user, 'range=all')->rangeStart)->toBeNull();
});

it('pins a week deep link to that week and suppresses the auto-widen flag', function (): void {
    $user = User::factory()->create();
    feedRun($user, 'Ancient', Carbon::now()->subDays(900));

    $filters = feedFiltersFor($user, 'week=2026-06-17');

    expect($filters->week?->toDateString())->toBe('2026-06-21')
        ->and($filters->rangeStart?->toDateString())->toBe('2026-06-15')
        ->and($filters->rangeAutoWidened)->toBeFalse();
});

it('bounds a week deep link on both sides', function (): void {
    $user = User::factory()->create();
    feedRun($user, 'In week', Carbon::parse('2026-06-21 23:30:00'));
    feedRun($user, 'Before week', Carbon::parse('2026-06-14 08:00:00'));
    feedRun($user, 'After week', Carbon::parse('2026-06-22 06:00:00'));

    expect(feedRunNames($user, 'week=2026-06-17'))->toBe(['In week']);
});

it('orders newest first', function (): void {
    $user = User::factory()->create();
    feedRun($user, 'First', Carbon::now()->subDays(4));
    feedRun($user, 'Second', Carbon::now()->subDays(3));

    expect(feedRunNames($user))->toBe(['Second', 'First']);
});

it('never leaks another user\'s runs', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    feedRun($user, 'Mine', Carbon::now()->subDays(3));
    feedRun($other, 'Theirs', Carbon::now()->subDays(3));

    expect(feedRunNames($user))->toBe(['Mine']);
});
