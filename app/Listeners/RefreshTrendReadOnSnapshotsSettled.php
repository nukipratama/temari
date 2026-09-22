<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\AI\RecentlyActiveUsers;
use App\Events\TrendSnapshotsSettled;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\AI\HistoryNarrationGate;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\TrendReadFingerprint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\DebounceFor;

#[DebounceFor(120)]
final readonly class RefreshTrendReadOnSnapshotsSettled implements ShouldQueue
{
    public function __construct(
        private AnalysisService $analysis,
        private RecentlyActiveUsers $activeUsers,
        private HistoryNarrationGate $history,
        private TrendReadFingerprint $fingerprint,
    ) {
    }

    public function debounceId(TrendSnapshotsSettled $event): string
    {
        return (string) $event->userId;
    }

    public function handle(TrendSnapshotsSettled $event): void
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Ingest);

        $user = User::query()->notDemo()->find($event->userId);
        if (
            $user === null
            || ! $this->activeUsers->includes($user)
            || $this->history->awaitsFullHydration($user->id)
            || $user->trend_snapshots_pending_from !== null
            || $user->trend_snapshots_rebuilding_from !== null
        ) {
            return;
        }

        $this->analysis->request(
            subjectOrType: AnalysisType::TrendRead->subjectType(),
            subjectId: $user->id,
            type: AnalysisType::TrendRead,
            discriminator: '7d',
            invalidate: $this->fingerprint->changed($user, '7d'),
        );
    }
}
