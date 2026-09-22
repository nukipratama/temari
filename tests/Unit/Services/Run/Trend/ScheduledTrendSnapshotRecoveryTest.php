<?php

declare(strict_types=1);

use App\Jobs\Run\ReconcileScheduledTrendSnapshotsJob;
use App\Models\User;
use App\Services\Run\Trend\ScheduledTrendSnapshotRecovery;
use App\Services\Run\Trend\TrendSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-08-17 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('reconciles from the earliest relevant date through yesterday', function (): void {
    $user = User::factory()->create(['created_at' => '2026-08-01 12:00:00']);
    $writer = Mockery::mock(TrendSnapshotWriter::class);
    $writer->shouldReceive('writeRange')
        ->once()
        ->withArgs(fn (User $actual, Carbon $from, Carbon $through): bool => $actual->is($user)
            && $from->toDateString() === '2026-08-01'
            && $through->toDateString() === '2026-08-16');

    expect(new ScheduledTrendSnapshotRecovery($writer)->recover($user))->toBeTrue()
        ->and($user->fresh()->trend_snapshots_scheduled_through?->toDateString())->toBe('2026-08-16');
});

it('continues long history in bounded chunks', function (): void {
    Bus::fake();
    $user = User::factory()->create(['created_at' => '2023-01-01 12:00:00']);
    $writer = Mockery::mock(TrendSnapshotWriter::class);
    $writer->shouldReceive('writeRange')->twice();

    app()->instance(TrendSnapshotWriter::class, $writer);
    expect(new ScheduledTrendSnapshotRecovery($writer)->recover($user))->toBeFalse()
        ->and($user->fresh()->trend_snapshots_scheduled_through?->toDateString())->toBe('2023-12-31');

    new ReconcileScheduledTrendSnapshotsJob($user->id)->handle(app(ScheduledTrendSnapshotRecovery::class));

    Bus::assertDispatched(ReconcileScheduledTrendSnapshotsJob::class);
});
