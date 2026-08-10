<?php

declare(strict_types=1);

use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\RelativeEffort;
use App\Services\Run\Story\PastYouMatcher;
use App\Support\Cooldown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('requires authentication for the index', function (): void {
    $this->get('/activities')->assertRedirect('/login');
});

it('requires authentication for the show page', function (): void {
    $activity = Activity::factory()->create();

    $this->get("/activities/{$activity->id}")->assertRedirect('/login');
});

it('requires authentication for the /catatan redirect', function (): void {
    $this->get('/catatan')->assertRedirect('/login');
});

it('lists the user\'s analyzed runs in reverse chronological order', function (): void {
    $user = User::factory()->create();
    $older = Activity::factory()->for($user)->analyzed()->create();
    $newer = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($older)->create(['name' => 'Older Run', 'start_date_local' => Carbon::now()->subDays(2)]);
    ActivityDetail::factory()->for($newer)->create(['name' => 'Newer Run', 'start_date_local' => Carbon::now()]);

    $this->actingAs($user)->get('/activities')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Activities/Feed')
            ->has('runs', 2)
            ->where('runs.0.detail.name', 'Newer Run')
            ->where('runs.1.detail.name', 'Older Run'));
});

it('ships the persisted post-run mood per run so the list mascot matches the backend', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);
    StoryLine::factory()->for($activity)->create(['kind' => StoryLine::KIND_POST_RUN, 'mood' => 'overloaded']);

    $this->actingAs($user)->get('/activities')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where("moods.{$activity->id}", 'overloaded'));
});

it('renders the empty state when the user has no analyzed runs yet', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/activities')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Activities/Feed')
            ->where('runs', [])
            ->where('rangeFilter', '8w'));
});

it('excludes runs outside the requested range', function (): void {
    $user = User::factory()->create();
    $recent = Activity::factory()->for($user)->analyzed()->create();
    $ancient = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($recent)->create(['name' => 'Recent', 'start_date_local' => Carbon::now()->subDays(10)]);
    ActivityDetail::factory()->for($ancient)->create(['name' => 'Ancient', 'start_date_local' => Carbon::now()->subDays(200)]);

    // Default 8w window excludes the 200-day-old run.
    $this->actingAs($user)->get('/activities')
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 1)
            ->where('runs.0.detail.name', 'Recent'));

    // 1y window includes both.
    $this->actingAs($user)->get('/activities?range=1y')
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 2)
            ->where('rangeFilter', '1y'));
});

/**
 * Two runs a week apart, moods 'gassed' and 'blazing', both inside the default
 * window. Returns [lemesActivity, nyalaActivity].
 *
 * @return array{0: Activity, 1: Activity}
 */
function moodFixtures(User $user): array
{
    $gassed = Activity::factory()->for($user)->analyzed()->create();
    $blazing = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($gassed)->create(['name' => 'Lemes run', 'start_date_local' => Carbon::now()->subDays(3)]);
    ActivityDetail::factory()->for($blazing)->create(['name' => 'Nyala run', 'start_date_local' => Carbon::now()->subDays(10)]);
    StoryLine::factory()->for($gassed)->create(['mood' => 'gassed']);
    StoryLine::factory()->for($blazing)->create(['mood' => 'blazing']);

    return [$gassed, $blazing];
}

it('returns every run when no mood filter is applied', function (): void {
    $user = User::factory()->create();
    moodFixtures($user);

    $this->actingAs($user)->get('/activities')
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 2)
            ->where('moodFilter', []));
});

// The filter used to run client-side and merely dim non-matching runs; it now
// removes them server-side, so the payload itself is narrowed.
it('filters runs down to the selected mood', function (): void {
    $user = User::factory()->create();
    moodFixtures($user);

    $this->actingAs($user)->get('/activities?mood=gassed')
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 1)
            ->where('runs.0.detail.name', 'Lemes run')
            ->where('moodFilter', ['gassed']));
});

it('treats multiple moods as a union', function (): void {
    $user = User::factory()->create();
    moodFixtures($user);

    $this->actingAs($user)->get('/activities?mood=gassed,blazing')
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 2)
            ->where('moodFilter', ['gassed', 'blazing']));
});

it('excludes a run whose post-run story line has not been written yet', function (): void {
    $user = User::factory()->create();
    $unnarrated = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($unnarrated)->create(['start_date_local' => Carbon::now()->subDays(2)]);

    $this->actingAs($user)->get('/activities?mood=gassed')
        ->assertInertia(fn (Assert $page) => $page->has('runs', 0));
});

it('never leaks another user runs through the mood filter', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    moodFixtures($other);

    $this->actingAs($user)->get('/activities?mood=gassed,blazing')
        ->assertInertia(fn (Assert $page) => $page->has('runs', 0));
});

// A stale or hand-edited link should widen, not 404.
it('ignores unknown moods rather than erroring', function (): void {
    $user = User::factory()->create();
    moodFixtures($user);

    $this->actingAs($user)->get('/activities?mood=bogus')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 2)
            ->where('moodFilter', []));

    $this->actingAs($user)->get('/activities?mood=bogus,gassed')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 1)
            ->where('moodFilter', ['gassed']));
});

it('combines the mood filter with the range window', function (): void {
    $user = User::factory()->create();
    $old = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($old)->create(['name' => 'Old gassed', 'start_date_local' => Carbon::now()->subDays(200)]);
    StoryLine::factory()->for($old)->create(['mood' => 'gassed']);
    moodFixtures($user);

    // Default window excludes the 200-day-old gassed run.
    $this->actingAs($user)->get('/activities?mood=gassed')
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 1)
            ->where('runs.0.detail.name', 'Lemes run'));

    // Widening the window brings it back.
    $this->actingAs($user)->get('/activities?mood=gassed&range=1y')
        ->assertInertia(fn (Assert $page) => $page->has('runs', 2));
});

/**
 * Three runs spanning the distance bands, all inside the default window.
 */
function distanceFixtures(User $user): void
{
    foreach ([['Sprint', 3_000], ['Sedang', 7_500], ['Long run', 25_000]] as [$name, $metres]) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'name' => $name,
            'distance' => $metres,
            'start_date_local' => Carbon::now()->subDays(5),
        ]);
    }
}

it('filters runs by distance band', function (string $band, array $expected): void {
    $user = User::factory()->create();
    distanceFixtures($user);

    $this->actingAs($user)->get("/activities?dist={$band}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', count($expected))
            ->where('distanceFilter', $band));
})->with([
    'under 5K' => ['0-5', ['Sprint']],
    '5 to 10K' => ['5-10', ['Sedang']],
    // The 25K run sits above the half-marathon cut, so the 10-21 band excludes it.
    '10K to half' => ['10-21', []],
    'half and up' => ['21up', ['Long run']],
]);

it('treats an unknown distance band as no filter', function (): void {
    $user = User::factory()->create();
    distanceFixtures($user);

    $this->actingAs($user)->get('/activities?dist=marathon')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 3)
            ->where('distanceFilter', null));
});

it('combines distance, mood and range', function (): void {
    $user = User::factory()->create();
    $match = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($match)->create([
        'name' => 'Long tempo',
        'distance' => 25_000,
        'start_date_local' => Carbon::now()->subDays(5),
    ]);
    StoryLine::factory()->for($match)->create(['mood' => 'blazing']);

    // Same distance band, wrong mood.
    $wrongMood = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($wrongMood)->create([
        'name' => 'Long easy',
        'distance' => 26_000,
        'start_date_local' => Carbon::now()->subDays(6),
    ]);
    StoryLine::factory()->for($wrongMood)->create(['mood' => 'chill']);

    $this->actingAs($user)->get('/activities?dist=21up&mood=blazing')
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 1)
            ->where('runs.0.detail.name', 'Long tempo'));
});

it('defaults to newest-first and reports the sort mode', function (): void {
    $user = User::factory()->create();
    distanceFixtures($user);

    $this->actingAs($user)->get('/activities')
        ->assertInertia(fn (Assert $page) => $page
            ->where('sortMode', 'newest')
            // Newest-first is activity id desc, and the fixtures insert in order.
            ->where('runs.0.detail.name', 'Long run'));
});

it('ranks by distance when sorting longest', function (): void {
    $user = User::factory()->create();
    distanceFixtures($user);

    $this->actingAs($user)->get('/activities?sort=longest')
        ->assertInertia(fn (Assert $page) => $page
            ->where('sortMode', 'longest')
            ->has('runs', 3)
            ->where('runs.0.detail.name', 'Long run')
            ->where('runs.1.detail.name', 'Sedang')
            ->where('runs.2.detail.name', 'Sprint'));
});

it('ranks by pace when sorting fastest', function (): void {
    $user = User::factory()->create();
    // 5K in 25min (5:00/km) vs 5K in 30min (6:00/km).
    $quick = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($quick)->create([
        'name' => 'Quick', 'distance' => 5_000, 'elapsed_time' => 1_500,
        'start_date_local' => Carbon::now()->subDays(3),
    ]);
    $slow = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($slow)->create([
        'name' => 'Slow', 'distance' => 5_000, 'elapsed_time' => 1_800,
        'start_date_local' => Carbon::now()->subDays(2),
    ]);

    $this->actingAs($user)->get('/activities?sort=fastest')
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 2)
            ->where('runs.0.detail.name', 'Quick')
            ->where('runs.1.detail.name', 'Slow'));
});

// A run with no distance or time has no pace, so it can't be ranked rather than
// sorting as infinitely fast.
it('drops pace-less runs from the fastest ranking', function (): void {
    $user = User::factory()->create();
    $paced = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($paced)->create([
        'name' => 'Paced', 'distance' => 5_000, 'elapsed_time' => 1_500,
        'start_date_local' => Carbon::now()->subDays(3),
    ]);
    $noDistance = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($noDistance)->create([
        'name' => 'No distance', 'distance' => 0, 'elapsed_time' => 1_500,
        'start_date_local' => Carbon::now()->subDays(2),
    ]);

    $this->actingAs($user)->get('/activities?sort=fastest')
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 1)
            ->where('runs.0.detail.name', 'Paced'));

    // It is still present in the default chronological view.
    $this->actingAs($user)->get('/activities')
        ->assertInertia(fn (Assert $page) => $page->has('runs', 2));
});

it('falls back to newest for an unknown sort', function (): void {
    $user = User::factory()->create();
    distanceFixtures($user);

    $this->actingAs($user)->get('/activities?sort=shortest')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('sortMode', 'newest'));
});

it('applies filters and sort together', function (): void {
    $user = User::factory()->create();
    distanceFixtures($user);

    // Only the 7.5K run is in the 5-10 band, whatever the ordering.
    $this->actingAs($user)->get('/activities?dist=5-10&sort=longest')
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 1)
            ->where('runs.0.detail.name', 'Sedang'));
});

describe('week deep link', function (): void {
    it('narrows to exactly that week and reports it', function (): void {
        $user = User::factory()->create();
        // Week ending Sunday 2026-05-17 runs Mon 11th - Sun 17th.
        foreach ([['In week', '2026-05-13'], ['Also in week', '2026-05-17'], ['Next week', '2026-05-18'], ['Week before', '2026-05-10']] as [$name, $date]) {
            $activity = Activity::factory()->for($user)->analyzed()->create();
            ActivityDetail::factory()->for($activity)->create(['name' => $name, 'start_date_local' => "{$date} 06:00:00"]);
        }

        $this->actingAs($user)->get('/activities?week=2026-05-17')
            ->assertInertia(fn (Assert $page) => $page
                ->where('weekFilter', '2026-05-17')
                ->has('runs', 2));
    });

    // The link is built from a week_ending date, but any day in that week should
    // resolve to the same Sunday so a hand-edited link still lands correctly.
    it('normalises any date in the week to that week Sunday', function (): void {
        $user = User::factory()->create();
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create(['start_date_local' => '2026-05-13 06:00:00']);

        $this->actingAs($user)->get('/activities?week=2026-05-13')
            ->assertInertia(fn (Assert $page) => $page
                ->where('weekFilter', '2026-05-17')
                ->has('runs', 1));
    });

    // A recap can be months old; the deep link must reach it regardless of the
    // default range window.
    it('reaches a week far outside the default range window', function (): void {
        $user = User::factory()->create();
        $old = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($old)->create([
            'name' => 'Ancient',
            'start_date_local' => Carbon::now()->subDays(300)->toDateTimeString(),
        ]);
        $weekEnding = Carbon::now()->subDays(300)->endOfWeek(Carbon::SUNDAY)->toDateString();

        $this->actingAs($user)->get("/activities?week={$weekEnding}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('runs', 1)
                ->where('runs.0.detail.name', 'Ancient')
                // The deep link is explicit, so it isn't an auto-widen.
                ->where('rangeAutoWidened', false));
    });

    it('shows only that week recap snapshot', function (): void {
        $user = User::factory()->create();
        WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17']);
        WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-24']);

        $this->actingAs($user)->get('/activities?week=2026-05-17')
            ->assertInertia(fn (Assert $page) => $page
                ->has('weeklySnapshots', 1)
                ->where('weeklySnapshots.0.week_ending', fn (string $w): bool => str_starts_with($w, '2026-05-17')));
    });

    // The head flag drives whether "Baca ulang" regenerates in place; getting it
    // wrong on a stale deep link (an old weekly-recap notification, opened after
    // later weeks have closed) would expose a regenerate that actually targets a
    // different week server-side and desyncs the chain.
    it('does not mislabel the deep-linked week as chain head when a later week exists', function (): void {
        $user = User::factory()->create();
        WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);
        WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-24', 'runs' => 3]);

        $this->actingAs($user)->get('/activities?week=2026-05-17')
            ->assertInertia(fn (Assert $page) => $page
                ->has('weeklySnapshots', 1)
                ->where('weeklySnapshots.0.is_chain_head', false));
    });

    it('ignores a malformed week rather than erroring', function (): void {
        $user = User::factory()->create();
        distanceFixtures($user);

        foreach (['not-a-date', '2026-13-45', ''] as $bad) {
            $this->actingAs($user)->get('/activities?week='.urlencode($bad))
                ->assertSuccessful()
                ->assertInertia(fn (Assert $page) => $page->where('weekFilter', null));
        }
    });

    it('still applies the other filters inside the week', function (): void {
        $user = User::factory()->create();
        foreach ([['Short', 3_000], ['Long', 25_000]] as [$name, $metres]) {
            $activity = Activity::factory()->for($user)->analyzed()->create();
            ActivityDetail::factory()->for($activity)->create([
                'name' => $name, 'distance' => $metres, 'start_date_local' => '2026-05-13 06:00:00',
            ]);
        }

        $this->actingAs($user)->get('/activities?week=2026-05-17&dist=21up')
            ->assertInertia(fn (Assert $page) => $page
                ->has('runs', 1)
                ->where('runs.0.detail.name', 'Long'));
    });
});

it('auto-widens the range and flags it when the newest run is outside the default window', function (): void {
    $user = User::factory()->create();
    $ancient = Activity::factory()->for($user)->analyzed()->create();
    // 200 days old: outside 8w (56d), 12w (84d) and 6m (182d); reaches into 1y.
    ActivityDetail::factory()->for($ancient)->create(['name' => 'Ancient', 'start_date_local' => Carbon::now()->subDays(200)]);

    $this->actingAs($user)->get('/activities')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rangeFilter', '1y')
            ->where('rangeAutoWidened', true)
            ->has('runs', 1)
            ->where('runs.0.detail.name', 'Ancient'));
});

it('escalates to the all range so a run older than every preset still shows', function (): void {
    $user = User::factory()->create();
    $ancient = Activity::factory()->for($user)->analyzed()->create();
    // 400 days old: beyond 8w/12w/6m/1y, so auto-widen falls through to "all".
    ActivityDetail::factory()->for($ancient)->create(['name' => 'Ancient', 'start_date_local' => Carbon::now()->subDays(400)]);

    $this->actingAs($user)->get('/activities')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rangeFilter', 'all')
            ->where('rangeAutoWidened', true)
            ->where('rangeStart', null)
            ->where('runsTruncated', false)
            ->has('runs', 1)
            ->where('runs.0.detail.name', 'Ancient'));
});

it('caps the runs list and flags truncation when a range holds more than the cap', function (): void {
    $user = User::factory()->create();
    // One past the 365 cap so truncation triggers; range=all has no lower bound.
    Activity::factory()->for($user)->analyzed()->has(ActivityDetail::factory(), 'detail')->count(366)->create();

    $this->actingAs($user)->get('/activities?range=all')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('runsTruncated', true)
            ->where('maxRuns', 365)
            ->has('runs', 365));
});

it('accepts an explicit all range with no lower bound', function (): void {
    $user = User::factory()->create();
    $ancient = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($ancient)->create(['name' => 'Ancient', 'start_date_local' => Carbon::now()->subDays(900)]);

    $this->actingAs($user)->get('/activities?range=all')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rangeFilter', 'all')
            ->where('rangeAutoWidened', false)
            ->has('runs', 1));
});

it('does not widen when runs exist in the requested window', function (): void {
    $user = User::factory()->create();
    $recent = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($recent)->create(['name' => 'Recent', 'start_date_local' => Carbon::now()->subDays(3)]);

    $this->actingAs($user)->get('/activities')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rangeFilter', '8w')
            ->where('rangeAutoWidened', false)
            ->has('runs', 1));
});

it('does not widen when the user has no analyzed runs', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/activities')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rangeFilter', '8w')
            ->where('rangeAutoWidened', false)
            ->where('runs', []));
});

it('keeps the requested wider range without flagging an auto-widen', function (): void {
    $user = User::factory()->create();
    $ancient = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($ancient)->create(['name' => 'Ancient', 'start_date_local' => Carbon::now()->subDays(200)]);

    // User explicitly asked for 1y, which already reaches the run: no widen flag.
    $this->actingAs($user)->get('/activities?range=1y')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rangeFilter', '1y')
            ->where('rangeAutoWidened', false)
            ->has('runs', 1));
});

it('falls back to the default range when the query value is invalid', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/activities?range=bogus')
        ->assertInertia(fn (Assert $page) => $page->where('rangeFilter', '8w'));
});

it('returns only weekly snapshots inside the range', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => Carbon::today()->toDateString(), 'distance_km' => 30.0]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => Carbon::today()->subDays(200)->toDateString(), 'distance_km' => 10.0]);

    $this->actingAs($user)->get('/activities')
        ->assertInertia(fn (Assert $page) => $page
            ->has('weeklySnapshots', 1)
            ->where('weeklySnapshots.0.distance_km', 30));
});

it('flags the in-progress week with is_current_week on each snapshot payload', function (): void {
    $user = User::factory()->create();
    $currentWeekEnding = Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => $currentWeekEnding->toDateString()]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => $currentWeekEnding->copy()->subWeek()->toDateString()]);

    // Ordered week_ending desc: the current week is first, the prior week second.
    $this->actingAs($user)->get('/activities')
        ->assertInertia(fn (Assert $page) => $page
            ->has('weeklySnapshots', 2)
            ->where('weeklySnapshots.0.is_current_week', true)
            ->where('weeklySnapshots.1.is_current_week', false));
});

it('redirects /catatan to /activities', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/catatan')
        ->assertRedirect('/activities');
});

it('returns null journeyMatch when the user has less than 2 activities', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()->subDays(2)]);

    $this->actingAs($user)->get('/activities')
        ->assertInertia(fn (Assert $page) => $page->where('journeyMatch', null));
});

it('returns null pace + hr improvement when either activity lacks the underlying data', function (): void {
    $user = User::factory()->create();
    $first = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($first)->create([
        'start_date_local' => Carbon::today()->subDays(60),
        'distance' => null, // missing distance → paceSecPerKm returns null
        'moving_time' => 2100,
        'average_heartrate' => null, // missing HR
        'name' => 'First Ever',
    ]);
    $latest = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($latest)->create([
        'start_date_local' => Carbon::today()->subDays(1),
        'distance' => 5000,
        'moving_time' => 1800,
        'average_heartrate' => 150,
        'name' => 'Latest',
    ]);

    $this->actingAs($user)->get('/activities?range=1y')
        ->assertInertia(fn (Assert $page) => $page
            ->where('journeyMatch.pace_improvement_sec', null)
            ->where('journeyMatch.hr_improvement_bpm', null));
});

it('builds journeyMatch comparing first-ever and most-recent activities', function (): void {
    $user = User::factory()->create();
    $first = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($first)->create([
        'start_date_local' => Carbon::today()->subDays(60),
        'distance' => 5000,
        'moving_time' => 2100, // 7:00/km
        'average_heartrate' => 165,
        'name' => 'First Ever',
    ]);
    $latest = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($latest)->create([
        'start_date_local' => Carbon::today()->subDays(1),
        'distance' => 5000,
        'moving_time' => 1800, // 6:00/km
        'average_heartrate' => 150,
        'name' => 'Latest',
    ]);

    $this->actingAs($user)->get('/activities')
        ->assertInertia(fn (Assert $page) => $page
            ->where('journeyMatch.first.name', 'First Ever')
            ->where('journeyMatch.current.name', 'Latest')
            ->where('journeyMatch.pace_improvement_sec', 60)
            ->where('journeyMatch.hr_improvement_bpm', 15));
});

it('folds journeyMatch to first + current boundaries and a lifetime total across many runs', function (): void {
    $user = User::factory()->create();
    foreach ([60, 45, 30, 1] as $i => $daysAgo) {
        $a = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($a)->create([
            'start_date_local' => Carbon::today()->subDays($daysAgo),
            'distance' => 5000,
            'moving_time' => 1800,
            'average_heartrate' => 150 + $i,
            'name' => "Run {$i}",
        ]);
    }

    // First/current land on the date boundaries; total_km sums every analyzed
    // detail (4 x 5km = 20km), proving the folded aggregate + boundary fetch.
    $this->actingAs($user)->get('/activities?range=1y')
        ->assertInertia(fn (Assert $page) => $page
            ->where('journeyMatch.first.name', 'Run 0')
            ->where('journeyMatch.current.name', 'Run 3')
            ->where('journeyMatch.total_km', 20));
});

it('shows a single run detail with Temari speech + run card', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'name' => 'Morning Run',
        'distance' => 10000,
        'moving_time' => 3600,
        'elapsed_time' => 3600,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z2' => 60, 'Z3' => 30, 'Z4' => 10],
            'per_km' => [['km' => 1, 'pace' => '6:00', 'avg_hr' => 150]],
            'decoupling_pct' => 4.2,
        ],
    ]);
    $card = RunCard::factory()->for($activity)->create(['special_move' => 'Paru-paru Baja', 'rarity' => 'epic']);
    StoryLine::factory()->for($activity)->create([
        'user_id' => $user->id,
        'speech' => 'Run yang solid, paru-paru baja keluar.',
    ]);

    $this->actingAs($user)->get("/activities/{$activity->id}")
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Show')
            ->where('detail.name', 'Morning Run')
            ->where('storyLine.speech', 'Run yang solid, paru-paru baja keluar.')
            ->where('card.special_move', 'Paru-paru Baja')
            ->has('card.flavor_analysis')
            ->where('card.edition', ['index' => 1, 'total' => 1])
            ->where('card.public_share_url', route('activities.show', ['activity' => $card->activity_id])));
});

it('numbers the run card\'s edition within its rarity across the user\'s collection', function (): void {
    $user = User::factory()->create();
    foreach (['First', 'Second', 'Third'] as $move) {
        $act = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($act)->create();
        RunCard::factory()->for($act)->create(['rarity' => 'rare', 'special_move' => $move]);
    }
    $latest = Activity::query()->whereHas('runCard', fn ($q) => $q->where('special_move', 'Second'))->firstOrFail();

    $this->actingAs($user)->get("/activities/{$latest->id}")
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('card.edition', ['index' => 2, 'total' => 3]));
});

it('surfaces the run speech Telegram cooldown when a send is on cooldown', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();
    $speech = Analysis::factory()->done('Mantap!')->create([
        'analysis_type' => AnalysisType::PostRunSpeech,
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'discriminator' => null,
    ]);
    RateLimiter::hit(Cooldown::notificationKey($speech->id), Cooldown::WINDOW_SECONDS);

    $this->actingAs($user)->get("/activities/{$activity->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('notificationRetryAfterSeconds', fn (?int $s): bool => $s !== null && $s > 0)
            ->etc());
});

it('surfaces the weekly recap Telegram cooldown on the snapshot payload', function (): void {
    $user = User::factory()->create();
    $snapshot = WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->toDateString(),
    ]);
    $recap = Analysis::factory()->done('Minggu ini 28 km.')->create([
        'analysis_type' => AnalysisType::WeeklyRecap,
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snapshot->id,
        'discriminator' => null,
    ]);
    RateLimiter::hit(Cooldown::notificationKey($recap->id), Cooldown::WINDOW_SECONDS);

    $this->actingAs($user)->get('/activities')
        ->assertInertia(fn (Assert $page) => $page
            ->where('weeklySnapshots.0.notification_retry_after_seconds', fn (?int $s): bool => $s !== null && $s > 0)
            ->etc());
});

it('404s when trying to view another user\'s run', function (): void {
    $other = User::factory()->create();
    $activity = Activity::factory()->for($other)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();

    $me = User::factory()->create();
    $this->actingAs($me)->get("/activities/{$activity->id}")->assertNotFound();
});

it('404s when the activity has not been analyzed yet', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();

    $this->actingAs($user)->get("/activities/{$activity->id}")->assertNotFound();
});

it('dispatches a ResolveActivityLocationJob when the run has coords but no resolved_at', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_resolved_at' => null,
    ]);

    $this->actingAs($user)->get("/activities/{$activity->id}")->assertSuccessful();

    Queue::assertPushed(ResolveActivityLocationJob::class, 1);
});

/**
 * `useAnalysisTrigger` reloads this page every 3-15s for up to 30 ticks while
 * the run insights generate. The job's `ShouldBeUnique` lock only spans the
 * queued-or-running window, and a transient Nominatim miss deliberately leaves
 * `location_resolved_at` null, so each finished-but-unresolved attempt freed the
 * lock for the next tick to re-queue against a rate-limited public endpoint.
 *
 * `releaseUniqueJobLocks()` is what makes this honest: without it the fake holds
 * the unique lock forever and the test would pass with no guard at all.
 */
it('does not re-dispatch a ResolveActivityLocationJob on every poll tick', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_resolved_at' => null,
    ]);

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($user)->get("/activities/{$activity->id}")->assertSuccessful();
        Queue::releaseUniqueJobLocks();
    }

    Queue::assertPushed(ResolveActivityLocationJob::class, 1);
});

it('dispatches for a different run while another run holds the guard', function (): void {
    Queue::fake();
    $user = User::factory()->create();

    $activities = collect(range(1, 2))->map(function () use ($user): Activity {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_lat' => -6.24,
            'start_lng' => 106.81,
            'location_resolved_at' => null,
        ]);

        return $activity;
    });

    foreach ($activities as $activity) {
        $this->actingAs($user)->get("/activities/{$activity->id}")->assertSuccessful();
        Queue::releaseUniqueJobLocks();
    }

    Queue::assertPushed(ResolveActivityLocationJob::class, 2);
});

it('does not dispatch a ResolveActivityLocationJob when already resolved', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_lat' => -6.24,
        'start_lng' => 106.81,
        'location_name' => 'Jakarta',
        'location_resolved_at' => now(),
    ]);

    $this->actingAs($user)->get("/activities/{$activity->id}")->assertSuccessful();

    Queue::assertNotPushed(ResolveActivityLocationJob::class);
});

it('does not dispatch when the run lacks coords', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_lat' => null,
        'start_lng' => null,
    ]);

    $this->actingAs($user)->get("/activities/{$activity->id}")->assertSuccessful();

    Queue::assertNotPushed(ResolveActivityLocationJob::class);
});

/**
 * The analysis poller reloads `weeklySnapshots` every 3-15s while a recap is
 * generating. Every prop used to be computed in the method body, so each tick
 * re-ran the full run query, the note reader and the mood reader for a prop it
 * never asked for. Behind closures, Inertia skips them entirely.
 *
 * Note which of the two tests below actually pins that. Inertia strips
 * unrequested props from the response whether or not they were computed, so the
 * prop-surface test passes even with eager props and only documents the
 * contract. The query test is the one that fails if a prop is un-closured.
 */
it('does not resolve the run payload on a partial reload that only wants snapshots', function (): void {
    $user = User::factory()->create();
    $run = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($run)->create(['start_date_local' => Carbon::now()]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay(),
    ]);

    $headers = snapshotOnlyHeaders($this->actingAs($user));

    // Asserted against the raw JSON rather than assertInertia(): that helper
    // reads the page object out of `viewData('page')`, which only exists on the
    // HTML response. A partial reload returns bare JSON and would always fail
    // its validity check.
    $response = $this->actingAs($user)->get('/activities', $headers)->assertSuccessful();

    $response->assertJsonPath('component', 'Activities/Feed');
    $response->assertJsonCount(1, 'props.weeklySnapshots');
    foreach (['runs', 'notes', 'moods', 'journeyMatch', 'runsTruncated'] as $skipped) {
        $response->assertJsonMissingPath("props.{$skipped}");
    }
});

it('runs no activity queries when only snapshots are requested', function (): void {
    $user = User::factory()->create();
    $run = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($run)->create(['start_date_local' => Carbon::now()]);

    $headers = snapshotOnlyHeaders($this->actingAs($user));

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($user)->get('/activities', $headers)->assertSuccessful();

    // `latestRunDaysAgo` still runs — it is a single MAX() that drives the
    // eager range scalars — so the assertion targets the payload queries the
    // poll used to drag along: the capped run fetch (MAX_RUNS + 1 = 366) and
    // the story-line reads behind `notes` and `moods`.
    $runFetches = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'limit 366'));
    $storyLineReads = array_filter($queries, fn (string $sql): bool => str_contains($sql, '`story_lines`'));

    expect($runFetches)->toBeEmpty()
        ->and($storyLineReads)->toBeEmpty();
});

it('fetches the capped run list exactly once on a full load', function (): void {
    $user = User::factory()->create();
    $run = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($run)->create(['start_date_local' => Carbon::now()]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($user)->get('/activities')->assertSuccessful();

    // `runs`, `notes`, `moods` and `runsTruncated` are four separate closures
    // over one memoized loader; more than one capped fetch means the
    // memoization broke and every prop re-ran the query.
    $runFetches = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'limit 366'));

    expect($runFetches)->toHaveCount(1);
});

it('still returns every prop on a full page load', function (): void {
    $user = User::factory()->create();
    $run = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($run)->create(['start_date_local' => Carbon::now()]);

    $this->actingAs($user)->get('/activities')
        ->assertSuccessful()
        ->assertInertia(
            fn (Assert $page) => $page
            ->has('runs', 1)
            ->has('notes')
            ->has('moods')
            ->has('weeklySnapshots')
            ->where('runsTruncated', false)
        );
});

/**
 * The four run insights are the whole reload set of the detail page's poll
 * (`DEFAULT_RELOAD_PROPS` in resources/js/components/run/FourLensGrid.tsx), and
 * it ticks every 3-15s for up to 30 attempts while narration generates. Every
 * prop used to be computed in the method body, so each tick re-ran the past-you
 * match, the relative-effort baseline, the card payload and the story-line
 * query for props it never asked for. Behind closures, Inertia skips them.
 */
it('does not run the past-you match or the relative-effort baseline on an insight-only partial reload', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);
    RunCard::factory()->for($activity)->create();

    // Built before the mocks: the helper loads the real page to read the asset
    // version, which legitimately invokes both collaborators.
    $headers = insightOnlyHeaders($this->actingAs($user), $activity->id);

    $this->mock(PastYouMatcher::class, fn ($mock) => $mock->shouldNotReceive('findMatch'));
    $this->mock(RelativeEffort::class, fn ($mock) => $mock->shouldNotReceive('forRun'));

    $response = $this->actingAs($user)->get("/activities/{$activity->id}", $headers)->assertSuccessful();

    $response->assertJsonPath('component', 'Runs/Show');
    $response->assertJsonPath('props.speechAnalysis.type', AnalysisType::PostRunSpeech->value);
    foreach (['pastYou', 'relativeEffort', 'card', 'storyLine', 'moodFallback', 'isChainHead'] as $skipped) {
        $response->assertJsonMissingPath("props.{$skipped}");
    }
});

it('still resolves the card payload on the card-only partial reload', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);
    RunCard::factory()->for($activity)->create(['special_move' => 'Paru-paru Baja']);

    $headers = insightOnlyHeaders($this->actingAs($user), $activity->id);
    $headers['X-Inertia-Partial-Data'] = 'card';

    $response = $this->actingAs($user)->get("/activities/{$activity->id}", $headers)->assertSuccessful();

    $response->assertJsonPath('props.card.special_move', 'Paru-paru Baja');
    $response->assertJsonPath('props.card.edition', ['index' => 1, 'total' => 1]);
});

it('still runs the past-you match and the relative-effort baseline on a full run-detail load', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);

    $this->mock(PastYouMatcher::class, fn ($mock) => $mock->shouldReceive('findMatch')->once()->andReturn(null));
    $this->mock(RelativeEffort::class, fn ($mock) => $mock->shouldReceive('forRun')->once()->andReturn(null));

    $this->actingAs($user)->get("/activities/{$activity->id}")
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Show')
            ->where('pastYou', null)
            ->where('relativeEffort', null));
});

it('runs no story-line queries when only the run insights are requested', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);
    RunCard::factory()->for($activity)->create();
    StoryLine::factory()->for($activity)->create(['kind' => StoryLine::KIND_POST_RUN]);

    $headers = insightOnlyHeaders($this->actingAs($user), $activity->id);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($user)->get("/activities/{$activity->id}")->assertSuccessful();
    $fullLoad = $queries;

    $queries = [];
    $this->actingAs($user)->get("/activities/{$activity->id}", $headers)->assertSuccessful();
    $partialReload = $queries;

    // `storyLine` and `moodFallback` are the only readers of story_lines on this
    // page, and neither is in the poll's reload set.
    $storyLineReads = array_filter($partialReload, fn (string $sql): bool => str_contains($sql, '`story_lines`'));

    expect($storyLineReads)->toBeEmpty()
        ->and(count($partialReload))->toBeLessThan(count($fullLoad));
});

/**
 * Partial-reload headers mimicking the analysis poller's
 * `router.reload({ only: ['weeklySnapshots'] })`.
 *
 * @param  object  $actingAs  The authenticated test case.
 * @return array<string, string>
 */
function snapshotOnlyHeaders(object $actingAs): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => inertiaVersionFor($actingAs, '/activities'),
        'X-Inertia-Partial-Component' => 'Activities/Feed',
        'X-Inertia-Partial-Data' => 'weeklySnapshots',
    ];
}

/**
 * Partial-reload headers mimicking the run-detail poller's
 * `router.reload({ only: [...the four insights] })`.
 *
 * @param  object  $actingAs  The authenticated test case.
 * @return array<string, string>
 */
function insightOnlyHeaders(object $actingAs, int $activityId): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => inertiaVersionFor($actingAs, "/activities/{$activityId}"),
        'X-Inertia-Partial-Component' => 'Runs/Show',
        'X-Inertia-Partial-Data' => 'speechAnalysis,insightTechnical,insightSplits,insightZones',
    ];
}
