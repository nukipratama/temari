<?php

declare(strict_types=1);

use App\Enums\PlanRegenerationReason;
use App\Jobs\Run\RegeneratePlanJob;
use App\Models\User;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanRegenerationService;
use Illuminate\Contracts\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('keys uniqueness by user and regeneration reason', function (): void {
    expect(new RegeneratePlanJob(42, PlanRegenerationReason::Settings)->uniqueId())->toBe('42:settings');
});

it('releases for retry when the regeneration lock is busy', function (): void {
    $user = User::factory()->create();
    $lock = Mockery::mock(CacheLock::class);
    $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException());
    Cache::shouldReceive('lock')
        ->once()
        ->with("plan-reconciliation:{$user->id}", 150)
        ->andReturn($lock);

    $job = new RegeneratePlanJob($user->id, PlanRegenerationReason::Manual)
        ->withFakeQueueInteractions();

    $job->handle(app(Periodizer::class), app(PlanRegenerationService::class));

    $job->assertReleased(10);
});

it('uses a time-bounded retry window instead of an attempt cap', function (): void {
    $job = new RegeneratePlanJob(42, PlanRegenerationReason::Settings);
    $retryUntil = $job->retryUntil();

    expect($retryUntil)->toBeInstanceOf(DateTimeInterface::class)
        ->and($retryUntil->getTimestamp())->toBeGreaterThanOrEqual(now()->addMinutes(9)->getTimestamp())
        ->and($retryUntil->getTimestamp())->toBeLessThanOrEqual(now()->addMinutes(10)->getTimestamp())
        ->and(property_exists($job, 'tries') ? $job->tries : null)->toBeNull();
});
