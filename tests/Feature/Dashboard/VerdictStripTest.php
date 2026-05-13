<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\Run\Story\Temari;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-05-11 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function seedDashboardVerdict(User $user, int $daysAgo, string $mood, string $speech): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subDays($daysAgo),
        'distance' => 5000.0 + ($daysAgo * 100),
        'trimp_edwards' => 60.0,
    ]);
    StoryLine::query()->create([
        'user_id' => $user->id,
        'activity_id' => $activity->id,
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => $mood,
        'speech' => $speech,
        'sigil_pattern' => 'dddd',
    ]);

    return $activity;
}

it('shows "Kata Temari" verdicts when the user has post-run StoryLines', function (): void {
    $user = User::factory()->create();
    seedDashboardVerdict($user, 0, Temari::MOOD_BOUNCY, 'Run yang mantap');

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('verdicts', 1)
            ->where('verdicts.0.oneline', 'Run yang mantap'));
});

it('omits verdicts when there are no post-run StoryLines', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'trimp_edwards' => 60.0,
    ]);

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('verdicts', []));
});

it('renders verdicts newest-first', function (): void {
    $user = User::factory()->create();
    seedDashboardVerdict($user, 0, Temari::MOOD_BOUNCY, 'verdict newest');
    seedDashboardVerdict($user, 2, Temari::MOOD_DIM, 'verdict older');

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('verdicts', 2)
            ->where('verdicts.0.oneline', 'verdict newest')
            ->where('verdicts.1.oneline', 'verdict older'));
});

it('does not leak verdicts across users', function (): void {
    $a = User::factory()->create();
    $b = User::factory()->create();
    seedDashboardVerdict($a, 0, Temari::MOOD_BOUNCY, 'a-only line');

    $this->actingAs($b)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('verdicts', []));
});
