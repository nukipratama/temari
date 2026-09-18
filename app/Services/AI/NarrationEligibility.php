<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Actions\AI\RecentlyActiveUsers;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The one place that ranks the reasons a run's narration is kept from the LLM:
 * demo, then too old, then pre-connect history older than the last
 * {@see \App\Actions\AI\RecentlyActiveUsers::ACTIVE_WINDOW_DAYS} days (awaiting
 * its backlog, on a manual trigger), then an athlete away from the app. A
 * historical run inside that window narrates like any other ingest — see
 * {@see HistoryNarrationGate::narratesAutomatically()}.
 *
 * @see docs/decisions/narration-spends-only-on-active-athletes.md
 * @see docs/decisions/history-narrates-on-demand.md
 */
final readonly class NarrationEligibility
{
    public function __construct(
        private BackfillAgeGate $ages,
        private HistoryNarrationGate $history,
        private RecentlyActiveUsers $activeUsers,
    ) {
    }

    public function forIngestedRun(User $user, ?Carbon $startedAt): NarrationVerdict
    {
        return match (true) {
            $user->is_demo => NarrationVerdict::Demo,
            $this->ages->isTooOld($startedAt) => NarrationVerdict::TooOld,
            $this->history->isHistorical($user, $startedAt)
                && ! $this->history->narratesAutomatically($startedAt) => NarrationVerdict::PreConnect,
            ! $this->activeUsers->includes($user) => NarrationVerdict::Inactive,
            default => NarrationVerdict::Eligible,
        };
    }

    /**
     * Never {@see NarrationVerdict::Inactive}: the click is the athlete using the app.
     */
    public function forManualTrigger(User $user, AnalysisType $type, int $subjectId, ?string $discriminator): NarrationVerdict
    {
        return match (true) {
            $user->is_demo => NarrationVerdict::Demo,
            $this->ages->blocksManualTrigger($type, $subjectId, $discriminator) => NarrationVerdict::TooOld,
            $this->history->awaitsHydration($user, $type, $subjectId) => NarrationVerdict::AwaitingBacklog,
            default => NarrationVerdict::Eligible,
        };
    }
}
