<?php

declare(strict_types=1);

use App\Jobs\Run\RecalibrateTrainingHistoryJob;
use App\Models\User;
use App\Services\Run\Plan\PlanRecalibrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the user id as its unique queue key', function (): void {
    expect(new RecalibrateTrainingHistoryJob(42)->uniqueId())->toBe('42');
});

it('ignores a demo user', function (): void {
    $demo = User::factory()->demo()->create();

    new RecalibrateTrainingHistoryJob($demo->id)->handle(app(PlanRecalibrationService::class));

    expect($demo->fresh()->plan_recalibration_started_at)->toBeNull();
});
