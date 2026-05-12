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

uses(RefreshDatabase::class);

it('creates the demo user, runs, cards, story lines, PRs, and weekly snapshots', function (): void {
    $exitCode = $this->artisan('demo:seed', ['--fresh' => true])->run();

    expect($exitCode)->toBe(0);

    $user = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->firstOrFail();

    // We expect ~74 runs (14 scripted + ~60 fillers, exact filler count varies
    // with the RNG seed but is deterministic between runs).
    $activityCount = Activity::query()->where('user_id', $user->id)->count();
    expect($activityCount)->toBeGreaterThan(30);

    expect(RunCard::query()->whereIn('activity_id', Activity::query()->where('user_id', $user->id)->pluck('id'))->count())
        ->toBe($activityCount)
        ->and(StoryLine::query()->where('user_id', $user->id)->where('kind', StoryLine::KIND_POST_RUN)->count())
        ->toBe($activityCount)
        ->and(StoryLine::query()->where('user_id', $user->id)->where('kind', StoryLine::KIND_DAILY_GREETING)->count())
        ->toBe(1)
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBeGreaterThan(8)
        ->and(PersonalRecord::query()->where('user_id', $user->id)->count())->toBeGreaterThanOrEqual(1);
});

it('is idempotent — re-running with --fresh produces a consistent row count', function (): void {
    $this->artisan('demo:seed', ['--fresh' => true])->assertSuccessful();
    $user = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->firstOrFail();
    $first = Activity::query()->where('user_id', $user->id)->count();

    $this->artisan('demo:seed', ['--fresh' => true])->assertSuccessful();
    $second = Activity::query()->where('user_id', $user->id)->count();

    expect($second)->toBe($first);
});
