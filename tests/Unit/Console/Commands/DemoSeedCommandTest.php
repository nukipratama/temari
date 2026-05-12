<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// Freeze today so the blueprint library's `Carbon::today()->subDays(...)`
// anchors and the ISO-week math are stable across machines / weekdays.
beforeEach(fn () => Carbon::setTestNow('2026-05-12 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('creates the demo user, runs, cards, story lines, PRs, and weekly snapshots', function (): void {
    $exitCode = $this->artisan('demo:seed', ['--fresh' => true])->run();

    expect($exitCode)->toBe(0);

    $user = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->firstOrFail();

    // Deterministic: 16 scripted blueprints + RNG-seeded fillers across
    // ~90 days @ 65% hit rate → 63 activities. Asserting exactly so a
    // blueprint or RNG drift fails loudly instead of slipping by.
    $activityCount = Activity::query()->where('user_id', $user->id)->count();
    expect($activityCount)->toBe(63);

    expect(RunCard::query()->whereIn('activity_id', Activity::query()->where('user_id', $user->id)->pluck('id'))->count())
        ->toBe($activityCount)
        ->and(StoryLine::query()->where('user_id', $user->id)->where('kind', StoryLine::KIND_POST_RUN)->count())
        ->toBe($activityCount)
        ->and(StoryLine::query()->where('user_id', $user->id)->where('kind', StoryLine::KIND_DAILY_GREETING)->count())
        ->toBe(1)
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBe(14)
        ->and(PersonalRecord::query()->where('user_id', $user->id)->count())->toBe(8);
});

it('is idempotent — re-running with --fresh produces a consistent row count', function (): void {
    $this->artisan('demo:seed', ['--fresh' => true])->assertSuccessful();
    $user = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->firstOrFail();
    $first = Activity::query()->where('user_id', $user->id)->count();

    $this->artisan('demo:seed', ['--fresh' => true])->assertSuccessful();
    $second = Activity::query()->where('user_id', $user->id)->count();

    expect($second)->toBe($first);
});
