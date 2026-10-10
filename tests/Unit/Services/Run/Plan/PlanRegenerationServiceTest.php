<?php

declare(strict_types=1);

use App\Enums\PlanRegenerationReason;
use App\Jobs\Run\RegeneratePlanJob;
use App\Models\User;
use App\Services\Run\Plan\PlanRegenerateCooldown;
use App\Services\Run\Plan\PlanRegenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('queues a manual regeneration after lock contention and starts its cooldown', function (): void {
    Sleep::fake(syncWithCarbon: true);
    Bus::fake();
    $user = User::factory()->create();
    $lock = Cache::lock("plan-reconciliation:{$user->id}", 3600);
    expect($lock->get())->toBeTrue();

    $regenerated = app(PlanRegenerationService::class)
        ->regenerateForRequest($user, PlanRegenerationReason::Manual);

    $lock->release();

    expect($regenerated)->toBeFalse()
        ->and(app(PlanRegenerateCooldown::class)->remaining($user))->not->toBeNull();

    Bus::assertDispatched(fn (RegeneratePlanJob $job): bool =>
        $job->userId === $user->id && $job->reason === PlanRegenerationReason::Manual);
});
