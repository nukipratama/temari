<?php

declare(strict_types=1);

use App\Events\ActivityIngested;
use App\Jobs\Run\ReconcilePlanJob;
use App\Listeners\ReconcilePlanAfterActivityIngested;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Attributes\DebounceFor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('debounces by user and marks an ingested activity for reconciliation', function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-08-17 12:00:00');
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::yesterday()]);
    $listener = app(ReconcilePlanAfterActivityIngested::class);

    expect($listener->debounceId(new ActivityIngested($activity->id, $user->id)))->toBe((string) $user->id)
        ->and($listener->handle(new ActivityIngested($activity->id, $user->id)))->toBeNull()
        ->and($user->fresh()->plan_reconciliation_pending_from->toDateString())->toBe('2026-08-16');

    Bus::assertDispatched(ReconcilePlanJob::class);
    Carbon::setTestNow();
});

it('has a two-minute queue debounce window', function (): void {
    $attribute = new ReflectionClass(ReconcilePlanAfterActivityIngested::class)
        ->getAttributes(DebounceFor::class)[0]
        ->newInstance();

    expect($attribute->debounceFor)->toBe(120);
});

it('ignores an ingested activity without detail', function (): void {
    Bus::fake();
    $activity = Activity::factory()->for(User::factory()->create())->create();

    app(ReconcilePlanAfterActivityIngested::class)->handle(new ActivityIngested($activity->id, $activity->user_id));

    Bus::assertNotDispatched(ReconcilePlanJob::class);
});
