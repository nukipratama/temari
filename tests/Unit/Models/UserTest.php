<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\StoryLine;
use App\Models\StravaConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('hides remember_token from array serialization', function (): void {
    $user = User::factory()->create();

    expect($user->toArray())->not->toHaveKey('remember_token');
});

it('has at most one strava connection', function (): void {
    $user = User::factory()->withStravaConnection()->create();

    expect($user->stravaConnection)->toBeInstanceOf(StravaConnection::class)
        ->and($user->stravaConnection->user->is($user))->toBeTrue();
});

it('returns null stravaConnection when none is attached', function (): void {
    $user = User::factory()->create();

    expect($user->stravaConnection)->toBeNull();
});

it('has many activities', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->count(2)->create();

    expect($user->activities)->toHaveCount(2);
});

it('has many personal records', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create(['category' => '5km']);
    PersonalRecord::factory()->for($user)->create(['category' => '10km']);

    expect($user->personalRecords)->toHaveCount(2);
});

it('has many weekly snapshots', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03']);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10']);

    expect($user->weeklySnapshots)->toHaveCount(2);
});

it('has many story lines', function (): void {
    $user = User::factory()->create();
    StoryLine::factory()->dailyGreeting('2026-05-10')->create(['user_id' => $user->id]);
    StoryLine::factory()->dailyGreeting('2026-05-11')->create(['user_id' => $user->id]);

    expect($user->storyLines)->toHaveCount(2);
});
