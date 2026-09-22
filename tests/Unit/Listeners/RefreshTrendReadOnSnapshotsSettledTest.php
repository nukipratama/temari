<?php

declare(strict_types=1);

use App\Actions\AI\RecentlyActiveUsers;
use App\Events\TrendSnapshotsSettled;
use App\Listeners\RefreshTrendReadOnSnapshotsSettled;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\HistoryNarrationGate;
use App\Services\AI\TrendReadFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('debounces by user and requests only an active athlete trend read', function (): void {
    $user = User::factory()->seenToday()->create();
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->once()
        ->withArgs(fn (string $subjectOrType, int $subjectId, mixed $type, ?string $discriminator, ?int $delaySeconds, bool $invalidate): bool => $subjectId === $user->id
            && $discriminator === '7d'
            && $invalidate === false)
        ->andReturn(new Analysis());
    $listener = new RefreshTrendReadOnSnapshotsSettled(
        $service,
        new RecentlyActiveUsers(),
        app(HistoryNarrationGate::class),
        app(TrendReadFingerprint::class),
    );
    $listener->handle(new TrendSnapshotsSettled($user->id));

    expect($listener->debounceId(new TrendSnapshotsSettled($user->id)))->toBe((string) $user->id);
});

it('does not read while a newer snapshot repair is still pending', function (): void {
    $user = User::factory()->seenToday()->create([
        'trend_snapshots_pending_from' => now()->subDay(),
    ]);
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')->never();
    $listener = new RefreshTrendReadOnSnapshotsSettled(
        $service,
        new RecentlyActiveUsers(),
        app(HistoryNarrationGate::class),
        app(TrendReadFingerprint::class),
    );

    $listener->handle(new TrendSnapshotsSettled($user->id));
});
