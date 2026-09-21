<?php

declare(strict_types=1);

use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs synchronously for the selected non-demo user only', function (): void {
    $selected = User::factory()->create();
    $other = User::factory()->create();
    $demo = User::factory()->demo()->create();

    $this->artisan('plan:recalibrate-history', ['--user' => $selected->id])
        ->expectsOutputToContain('Recalibrated 1 non-demo user(s).')
        ->assertSuccessful();

    expect($selected->fresh()->plan_recalibration_completed_at)->not->toBeNull()
        ->and($other->fresh()->plan_recalibration_started_at)->toBeNull()
        ->and($demo->fresh()->plan_recalibration_started_at)->toBeNull();
});

it('reports a dry run without retaining plan writes or markers', function (): void {
    $user = User::factory()->create();

    $this->artisan('plan:recalibrate-history', ['--user' => $user->id, '--dry-run' => true])
        ->expectsOutputToContain('(dry run)')
        ->assertSuccessful();

    expect($user->fresh()->plan_recalibration_started_at)->toBeNull()
        ->and(PlannedSession::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
