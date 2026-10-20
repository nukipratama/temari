<?php

declare(strict_types=1);

use App\Jobs\Run\CheckFitnessImprovementJob;
use App\Models\User;
use App\Services\Run\Metrics\FitnessImprovementChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-05 11:00:00'));

afterEach(fn () => Carbon::setTestNow());

it('keys uniqueness by user', function (): void {
    expect(new CheckFitnessImprovementJob(42)->uniqueId())->toBe('42');
});

it('checks the athlete and stamps the check', function (): void {
    $user = User::factory()->create();

    new CheckFitnessImprovementJob($user->id)->handle(app(FitnessImprovementChecker::class));

    expect(nonNull($user->refresh()->fitness_checked_at)->toDateTimeString())->toBe('2026-10-05 11:00:00');
});

it('skips the demo user', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);

    new CheckFitnessImprovementJob($demo->id)->handle(app(FitnessImprovementChecker::class));

    expect($demo->refresh()->fitness_checked_at)->toBeNull();
});
