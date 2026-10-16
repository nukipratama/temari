<?php

declare(strict_types=1);

use App\Jobs\IntervalsIcu\SyncIntervalsIcuWorkoutJob;
use App\Models\IntervalsIcuConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('queues a sync for each working connection, skipping broken keys and the demo user', function (): void {
    Queue::fake();
    $working = IntervalsIcuConnection::factory()->create();
    IntervalsIcuConnection::factory()->broken()->create();
    IntervalsIcuConnection::factory()->for(User::factory()->create(['is_demo' => true]))->create();
    User::factory()->create();

    $this->artisan('intervals-icu:sync')->assertSuccessful();

    Queue::assertPushed(SyncIntervalsIcuWorkoutJob::class, 1);
    Queue::assertPushed(SyncIntervalsIcuWorkoutJob::class, fn (SyncIntervalsIcuWorkoutJob $job): bool => $job->userId === $working->user_id);
});
