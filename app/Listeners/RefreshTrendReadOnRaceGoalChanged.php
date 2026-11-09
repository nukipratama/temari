<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\AI\RecentlyActiveUsers;
use App\Events\RaceGoalChanged;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\TrendReadFingerprint;
use App\Services\Run\Ingest\HydrationBacklog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\DebounceFor;

#[DebounceFor(60)]
final readonly class RefreshTrendReadOnRaceGoalChanged implements ShouldQueue
{
    public function __construct(
        private AnalysisService $analysis,
        private RecentlyActiveUsers $activeUsers,
        private HydrationBacklog $backlog,
        private TrendReadFingerprint $fingerprint,
    ) {
    }

    public function debounceId(RaceGoalChanged $event): string
    {
        return (string) $event->userId;
    }

    public function handle(RaceGoalChanged $event): void
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::User);

        $user = User::query()->notDemo()->find($event->userId);
        if (
            $user === null
            || ! $this->activeUsers->includes($user)
            || $this->backlog->awaitsFullHydration($user->id)
            || $user->trend_snapshots_pending_from !== null
            || $user->trend_snapshots_rebuilding_from !== null
            || ! $this->fingerprint->changed($user)
        ) {
            return;
        }

        $row = Analysis::query()
            ->forSubject(AnalysisType::TREND_READ_SUBJECT_TYPE, $user->id, AnalysisType::TrendRead, AnalysisType::TREND_READ_RANGES[0])
            ->first();
        if ($row?->cooldownRemaining() !== null) {
            return;
        }

        $this->analysis->request(
            subjectOrType: AnalysisType::TREND_READ_SUBJECT_TYPE,
            subjectId: $user->id,
            type: AnalysisType::TrendRead,
            discriminator: AnalysisType::TREND_READ_RANGES[0],
            invalidate: true,
        );
    }
}
