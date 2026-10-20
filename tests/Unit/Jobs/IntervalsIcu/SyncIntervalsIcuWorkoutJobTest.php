<?php

declare(strict_types=1);

use App\Enums\IntervalsIcuSyncStatus;
use App\Jobs\IntervalsIcu\SyncIntervalsIcuWorkoutJob;
use App\Models\IntervalsIcuConnection;
use App\Models\PlannedSession;
use App\Services\IntervalsIcu\IntervalsIcuSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it("syncs the athlete's connection for today", function (): void {
    Carbon::setTestNow('2026-10-14 06:00:00');
    Http::fake([
        'intervals.icu/api/v1/athlete/*/events/bulk*' => Http::response([['id' => 501]]),
        'intervals.icu/*' => Http::response(['sportSettings' => [['types' => ['Run'], 'lthr' => 163]]]),
    ]);
    $connection = IntervalsIcuConnection::factory()->create();
    PlannedSession::factory()->for($connection->user)->create(['date' => '2026-10-14']);

    new SyncIntervalsIcuWorkoutJob($connection->user_id)->handle(app(IntervalsIcuSync::class));

    expect($connection->refresh()->statusOn(Carbon::today()))->toBe(IntervalsIcuSyncStatus::Sent);
});

it('does nothing once the athlete has disconnected', function (): void {
    Http::fake();

    new SyncIntervalsIcuWorkoutJob(999)->handle(app(IntervalsIcuSync::class));

    Http::assertNothingSent();
});

it('is unique per athlete', function (): void {
    expect(new SyncIntervalsIcuWorkoutJob(7)->uniqueId())->toBe('7');
});
