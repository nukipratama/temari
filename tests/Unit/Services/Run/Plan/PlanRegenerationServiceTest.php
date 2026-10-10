<?php

declare(strict_types=1);

use App\Enums\PlanRegenerationReason;
use App\Jobs\AI\AnalyzePlanSeasonVoiceJob;
use App\Jobs\Run\RegeneratePlanJob;
use App\Models\AI\Analysis;
use App\Models\Season;
use App\Models\User;
use App\Services\Run\Plan\PlanRegenerateCooldown;
use App\Services\Run\Plan\PlanRegenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Sleep;
use Illuminate\Support\Facades\Queue;
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

it('has the season narration request in place before regenerateForRequest returns', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create();

    $regenerated = app(PlanRegenerationService::class)
        ->regenerateForRequest($user, PlanRegenerationReason::Manual);

    expect($regenerated)->toBeTrue()
        ->and(Analysis::query()->where('subject_type', Season::class)->where('subject_id', $season->id)->exists())->toBeTrue();
    Queue::assertPushed(AnalyzePlanSeasonVoiceJob::class);
    Queue::assertNotPushed(CallQueuedListener::class);
});
