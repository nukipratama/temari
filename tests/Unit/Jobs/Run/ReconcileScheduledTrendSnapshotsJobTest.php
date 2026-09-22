<?php

declare(strict_types=1);

use App\Jobs\Run\ReconcileScheduledTrendSnapshotsJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('is unique per athlete with bounded retry settings', function (): void {
    $user = User::factory()->create();
    $job = new ReconcileScheduledTrendSnapshotsJob($user->id);

    expect($job->uniqueId())->toBe((string) $user->id)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([30, 120]);
});
