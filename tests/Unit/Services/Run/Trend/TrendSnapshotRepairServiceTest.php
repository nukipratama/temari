<?php

declare(strict_types=1);

use App\Models\TrendDailySnapshot;
use App\Events\TrendSnapshotsSettled;
use App\Jobs\Run\RebuildTrendSnapshotsJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Trend\TrendSnapshotRepairService;
use App\Services\Run\Trend\TrendSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('keeps the earliest pending date when several activities arrive', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $service = app(TrendSnapshotRepairService::class);

    $service->markDirty($user->id, Carbon::parse('2026-08-15'));
    $service->markDirty($user->id, Carbon::parse('2026-08-16'));

    expect($user->fresh()->trend_snapshots_pending_from->toDateString())->toBe('2026-08-15');
    Bus::assertDispatched(RebuildTrendSnapshotsJob::class);
});

it('writes the closed range through today and emits settlement when no work remains', function (): void {
    Event::fake([TrendSnapshotsSettled::class]);
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-08-16 06:00:00'),
        'stream_summary' => ['pace_variability_sec' => 6.0],
    ]);
    $user->forceFill(['trend_snapshots_pending_from' => Carbon::parse('2026-08-15')])->saveQuietly();

    app(TrendSnapshotRepairService::class)->drain($user->id);

    expect($user->fresh()->trend_snapshots_pending_from)->toBeNull()
        ->and($user->fresh()->trend_snapshots_rebuilding_from)->toBeNull()
        ->and(TrendDailySnapshot::query()->where('user_id', $user->id)->count())->toBe(3);
    Event::assertDispatched(TrendSnapshotsSettled::class, fn (TrendSnapshotsSettled $event): bool => $event->userId === $user->id);
});

it('retains a new earlier date for a second bounded pass', function (): void {
    Bus::fake();
    $user = User::factory()->create([
        'trend_snapshots_rebuilding_from' => Carbon::parse('2026-08-15'),
        'trend_snapshots_pending_from' => Carbon::parse('2026-08-14'),
    ]);
    $writer = Mockery::mock(TrendSnapshotWriter::class);
    $writer->shouldReceive('writeRange')
        ->once()
        ->withArgs(fn (User $actual, Carbon $from, Carbon $through): bool => $actual->is($user)
            && $from->toDateString() === '2026-08-15'
            && $through->toDateString() === '2026-08-17')
        ->andReturn(3);

    app()->instance(TrendSnapshotWriter::class, $writer);
    app(TrendSnapshotRepairService::class)->drain($user->id);

    expect($user->fresh()->trend_snapshots_pending_from)->toBeNull()
        ->and($user->fresh()->trend_snapshots_rebuilding_from->toDateString())->toBe('2026-08-14');
    Bus::assertDispatched(RebuildTrendSnapshotsJob::class);
});
