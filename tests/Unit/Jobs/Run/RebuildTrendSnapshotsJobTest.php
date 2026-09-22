<?php

declare(strict_types=1);

use App\Jobs\Run\RebuildTrendSnapshotsJob;
use App\Models\User;
use App\Services\Run\Trend\TrendSnapshotRepairService;
use App\Services\Run\Trend\TrendSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('is unique per user and delegates to the repair service', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['trend_snapshots_pending_from' => now()->subDay()])->saveQuietly();
    $writer = Mockery::mock(TrendSnapshotWriter::class);
    $writer->shouldReceive('writeRange')->once();
    $repair = new TrendSnapshotRepairService($writer);

    $job = new RebuildTrendSnapshotsJob($user->id);
    $job->handle($repair);

    expect($job->uniqueId())->toBe((string) $user->id)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([30, 120]);
});
