<?php

declare(strict_types=1);

use App\Http\Requests\JejakFilterRequest;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\Run\JejakFilters;
use App\Services\Run\JejakQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function jejakFiltersFor(User $user, string $query = ''): JejakFilters
{
    return app(JejakQuery::class)->filtersFor(
        $user,
        JejakFilterRequest::create('/activities'.($query === '' ? '' : '?'.$query)),
    );
}

/** @return array<int, string> */
function jejakRunNames(User $user, string $query = ''): array
{
    $jejak = app(JejakQuery::class);

    return $jejak->for($user, jejakFiltersFor($user, $query))
        ->get()
        ->map(fn (Activity $run): string => (string) $run->detail?->name)
        ->all();
}

function jejakRun(User $user, string $name, Carbon $date, ?float $distance = null, ?int $elapsedTime = null): Activity
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
    jejakRun($user, 'Recent', Carbon::now()->subDays(3));

    $filters = jejakFiltersFor($user);

    expect($filters->range)->toBe('8w')
        ->and($filters->rangeAutoWidened)->toBeFalse()
        ->and($filters->rangeStart?->toDateString())->toBe(Carbon::today()->subDays(55)->toDateString());
});

it('keeps the requested range when the user has no runs at all', function (): void {
    $filters = jejakFiltersFor(User::factory()->create());

    expect($filters->range)->toBe('8w')
        ->and($filters->rangeAutoWidened)->toBeFalse();
});

it('widens to the smallest preset that reaches the newest run', function (int $daysAgo, string $expected): void {
    $user = User::factory()->create();
    jejakRun($user, 'Old', Carbon::now()->subDays($daysAgo));

    $filters = jejakFiltersFor($user);

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
    jejakRun($user, 'Ancient', Carbon::now()->subDays(900));

    expect(jejakFiltersFor($user, 'range=all')->rangeStart)->toBeNull();
});

it('pins a week deep link to that week and suppresses the auto-widen flag', function (): void {
    $user = User::factory()->create();
    jejakRun($user, 'Ancient', Carbon::now()->subDays(900));

    $filters = jejakFiltersFor($user, 'week=2026-06-17');

    expect($filters->week?->toDateString())->toBe('2026-06-21')
        ->and($filters->rangeStart?->toDateString())->toBe('2026-06-15')
        ->and($filters->rangeAutoWidened)->toBeFalse();
});

it('bounds a week deep link on both sides', function (): void {
    $user = User::factory()->create();
    jejakRun($user, 'In week', Carbon::parse('2026-06-21 23:30:00'));
    jejakRun($user, 'Before week', Carbon::parse('2026-06-14 08:00:00'));
    jejakRun($user, 'After week', Carbon::parse('2026-06-22 06:00:00'));

    expect(jejakRunNames($user, 'week=2026-06-17'))->toBe(['In week']);
});

it('filters by mood through the post-run story line', function (): void {
    $user = User::factory()->create();
    $lemes = jejakRun($user, 'Lemes run', Carbon::now()->subDays(3));
    jejakRun($user, 'No story line', Carbon::now()->subDays(4));
    StoryLine::factory()->for($lemes)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'lemes']);

    expect(jejakRunNames($user, 'mood=lemes'))->toBe(['Lemes run'])
        ->and(jejakRunNames($user, 'mood=nyala'))->toBe([]);
});

it('filters by distance band with an exclusive upper bound', function (): void {
    $user = User::factory()->create();
    jejakRun($user, 'Five', Carbon::now()->subDays(3), 4999.0);
    jejakRun($user, 'Ten', Carbon::now()->subDays(4), 5000.0);

    expect(jejakRunNames($user, 'dist=0-5'))->toBe(['Five'])
        ->and(jejakRunNames($user, 'dist=5-10'))->toBe(['Ten']);
});

it('leaves an open-ended top band', function (): void {
    $user = User::factory()->create();
    jejakRun($user, 'Ultra', Carbon::now()->subDays(3), 80000.0);

    expect(jejakRunNames($user, 'dist=21up'))->toBe(['Ultra']);
});

it('orders newest first by default', function (): void {
    $user = User::factory()->create();
    jejakRun($user, 'First', Carbon::now()->subDays(4));
    jejakRun($user, 'Second', Carbon::now()->subDays(3));

    expect(jejakRunNames($user))->toBe(['Second', 'First']);
});

it('ranks by distance for the longest sort', function (): void {
    $user = User::factory()->create();
    jejakRun($user, 'Short', Carbon::now()->subDays(3), 3000.0);
    jejakRun($user, 'Long', Carbon::now()->subDays(4), 30000.0);

    expect(jejakRunNames($user, 'sort=longest'))->toBe(['Long', 'Short']);
});

it('ranks by pace for the fastest sort and drops runs with no pace', function (): void {
    $user = User::factory()->create();
    jejakRun($user, 'Slow', Carbon::now()->subDays(3), 10000.0, 4000);
    jejakRun($user, 'Fast', Carbon::now()->subDays(4), 10000.0, 3000);
    jejakRun($user, 'No pace', Carbon::now()->subDays(5), 0.0, 0);

    expect(jejakRunNames($user, 'sort=fastest'))->toBe(['Fast', 'Slow']);
});

it('never leaks another user\'s runs', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    jejakRun($user, 'Mine', Carbon::now()->subDays(3));
    jejakRun($other, 'Theirs', Carbon::now()->subDays(3));

    expect(jejakRunNames($user))->toBe(['Mine']);
});
