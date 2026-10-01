<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Run\Plan\CoachingReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('skips a user already reset without opening a transaction', function (): void {
    $user = User::factory()->create(['coaching_reset_at' => Carbon::parse('2026-10-01 10:00:00')]);

    expect(app(CoachingReset::class)->reset($user))->toBe(['skipped' => true, 'before' => [], 'after' => []])
        ->and($user->fresh()->plan_recalibration_started_at)->toBeNull();
});

it('refuses the demo athlete', function (): void {
    app(CoachingReset::class)->reset(User::factory()->demo()->create());
})->throws(InvalidArgumentException::class);

it('counts the same labels before and after a reset of a user with no history', function (): void {
    Queue::fake();
    $user = User::factory()->create();

    $result = app(CoachingReset::class)->reset($user, dryRun: true);

    expect($result['skipped'])->toBeFalse()
        ->and(array_keys($result['after']))->toBe(array_keys($result['before']))
        ->and($result['before'])->toHaveKeys(['personal records', 'past days done', 'past days unknown effort', 'stale narrations'])
        ->and($user->fresh()->coaching_reset_at)->toBeNull();
});
