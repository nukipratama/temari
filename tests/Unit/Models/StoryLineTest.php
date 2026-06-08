<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\StoryLine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('casts for_date to Carbon', function (): void {
    $line = StoryLine::factory()->dailyGreeting('2026-05-10')->create();

    expect($line->for_date)->toBeInstanceOf(Carbon::class)
        ->and($line->for_date->toDateString())->toBe('2026-05-10');
});

it('belongs to a user and (optionally) an activity', function (): void {
    $activity = Activity::factory()->create();
    $line = StoryLine::factory()->for($activity)->create([
        'user_id' => $activity->user_id,
    ]);

    expect($line->user)->toBeInstanceOf(User::class)
        ->and($line->activity)->toBeInstanceOf(Activity::class)
        ->and($line->activity->is($activity))->toBeTrue();
});

it('keeps the unique (user_id, activity_id) per post_run line', function (): void {
    $activity = Activity::factory()->create();
    StoryLine::factory()->for($activity)->create(['user_id' => $activity->user_id]);

    expect(fn () => StoryLine::factory()->for($activity)->create(['user_id' => $activity->user_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps one daily greeting per (user_id, for_date)', function (): void {
    $user = User::factory()->create();
    StoryLine::factory()->dailyGreeting('2026-05-10')->create(['user_id' => $user->id]);

    expect(fn () => StoryLine::factory()->dailyGreeting('2026-05-10')->create(['user_id' => $user->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows many daily greetings for distinct dates', function (): void {
    $user = User::factory()->create();
    StoryLine::factory()->dailyGreeting('2026-05-10')->create(['user_id' => $user->id]);
    StoryLine::factory()->dailyGreeting('2026-05-11')->create(['user_id' => $user->id]);

    expect(StoryLine::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('exposes kind constants', function (): void {
    expect(StoryLine::KIND_POST_RUN)->toBe('post_run')
        ->and(StoryLine::KIND_DAILY_GREETING)->toBe('daily_greeting');
});
