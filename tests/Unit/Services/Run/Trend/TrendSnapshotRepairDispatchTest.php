<?php

declare(strict_types=1);

use App\Jobs\Run\RebuildTrendSnapshotsJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Trend\TrendSnapshotRepairDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('marks an activity date dirty after detail ingest', function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-08-17 12:00:00');
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::yesterday()]);

    app(TrendSnapshotRepairDispatch::class)->forActivity($activity->load('detail'));

    expect($activity->user->fresh()->trend_snapshots_pending_from->toDateString())->toBe('2026-08-16');
    Bus::assertDispatched(RebuildTrendSnapshotsJob::class);
    Carbon::setTestNow();
});

it('does not schedule a repair for the demo account', function (): void {
    Bus::fake();
    $activity = Activity::factory()->for(User::factory()->demo())->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::yesterday()]);

    app(TrendSnapshotRepairDispatch::class)->forActivity($activity->load('detail'));

    expect($activity->user->fresh()->trend_snapshots_pending_from)->toBeNull();
    Bus::assertNotDispatched(RebuildTrendSnapshotsJob::class);
});
