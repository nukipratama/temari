<?php

declare(strict_types=1);

use App\Actions\AI\RecentlyActiveUsers;
use App\Events\TrendSnapshotsSettled;
use App\Listeners\RefreshTrendReadOnSnapshotsSettled;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\Run\Ingest\HydrationBacklog;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\TrendReadFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

it('debounces by user and requests only an active athlete trend read', function (): void {
    $user = User::factory()->seenToday()->create();
    /** @var AnalysisService&MockInterface $service */
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->once()
        ->withArgs(fn (string $subjectOrType, int $subjectId, mixed $type, ?string $discriminator, ?int $delaySeconds, bool $invalidate): bool => $subjectId === $user->id
            && $discriminator === 'arc'
            && $invalidate === false
            && app(NarrationOrigin::class)->current() === AnalysisOrigin::Ingest)
        ->andReturn(new Analysis());
    $listener = new RefreshTrendReadOnSnapshotsSettled(
        $service,
        new RecentlyActiveUsers(),
        app(HydrationBacklog::class),
        app(TrendReadFingerprint::class),
    );
    $listener->handle(new TrendSnapshotsSettled($user->id));

    expect($listener->debounceId(new TrendSnapshotsSettled($user->id)))->toBe((string) $user->id);
});

it('does not read while a newer snapshot repair is still pending', function (): void {
    $user = User::factory()->seenToday()->create([
        'trend_snapshots_pending_from' => now()->subDay(),
    ]);
    /** @var AnalysisService&MockInterface $service */
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')->never();
    $listener = new RefreshTrendReadOnSnapshotsSettled(
        $service,
        new RecentlyActiveUsers(),
        app(HydrationBacklog::class),
        app(TrendReadFingerprint::class),
    );

    $listener->handle(new TrendSnapshotsSettled($user->id));
});

it('keeps a done read through a mid-week settle and re-narrates it once the week has closed', function (string $now, bool $invalidate): void {
    Carbon::setTestNow('2026-08-19 12:00:00');
    $user = User::factory()->seenToday()->create();
    Analysis::factory()->done('a read')->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => 'arc',
        'content_fingerprint' => app(TrendReadFingerprint::class)->forUser($user),
    ]);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse('2026-08-18 07:00:00'), 'distance' => 8_000]);
    Carbon::setTestNow($now);
    $user->forceFill(['last_seen_at' => now()])->save();
    /** @var AnalysisService&MockInterface $service */
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->once()
        ->withArgs(fn (string $subjectOrType, int $subjectId, mixed $type, ?string $discriminator, ?int $delaySeconds, bool $flag): bool => $flag === $invalidate)
        ->andReturn(new Analysis());

    new RefreshTrendReadOnSnapshotsSettled($service, new RecentlyActiveUsers(), app(HydrationBacklog::class), app(TrendReadFingerprint::class))
        ->handle(new TrendSnapshotsSettled($user->id));

    Carbon::setTestNow();
})->with([
    'the same week' => ['2026-08-21 18:00:00', false],
    'after it closed' => ['2026-08-24 09:00:00', true],
]);
