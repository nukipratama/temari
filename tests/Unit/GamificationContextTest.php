<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Gamification\GamificationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns zero counts for a user with no data', function (): void {
    $user = User::factory()->create();

    $ctx = GamificationContext::forUser($user);

    expect($ctx->user)->toBe($user)
        ->and($ctx->prCount)->toBe(0)
        ->and($ctx->activityCount)->toBe(0)
        ->and($ctx->totalDistanceM)->toBe(0.0)
        ->and($ctx->totalDistanceKm())->toBe(0.0)
        ->and($ctx->streakWeeks)->toBe(0)
        ->and($ctx->twoWeekStreak)->toBe(0)
        ->and($ctx->tenKPlus)->toBe(0)
        ->and($ctx->fiveKPlus)->toBe(0)
        ->and($ctx->halfMarathon)->toBe(0)
        ->and($ctx->fastPace)->toBe(0)
        ->and($ctx->badgeCounts)->toBe([
            'anak_malam' => 0,
            'anak_pagi' => 0,
            'pejuang_hujan' => 0,
            'negative_split' => 0,
            'hari_panas' => 0,
            'z2_master' => 0,
        ]);
});

it('accumulates stats from activities and PRs', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->count(3)->create();
    Activity::factory()->for($user)->count(5)->create();

    $ctx = GamificationContext::forUser($user);

    expect($ctx->prCount)->toBe(3)
        ->and($ctx->activityCount)->toBe(5);
});

it('converts totalDistanceM to km', function (): void {
    $user = User::factory()->create();

    // Create an activity with a detail that has distance.
    $activity = Activity::factory()->for($user)->create();
    $activity->detail()->create([
        'distance' => 15000.0,
        'moving_time' => 3600,
    ]);

    $ctx = GamificationContext::forUser($user);

    expect($ctx->totalDistanceM)->toBe(15000.0)
        ->and($ctx->totalDistanceKm())->toBe(15.0);
});
