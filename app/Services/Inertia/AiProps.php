<?php

declare(strict_types=1);

namespace App\Services\Inertia;

use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\NarrationGate;
use App\Services\AI\AnalysisStatus;
use App\Services\Run\Ingest\HydrationBacklog;
use App\Support\SharedPropCacheKey;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The narration-pipeline signal the whole app can see.
 *
 * Returned as a closure, so Inertia skips the work entirely on a partial reload
 * that did not ask for the key.
 */
final readonly class AiProps
{
    public function __construct(
        private NarrationGate $gate,
        private HydrationBacklog $backlog,
    ) {
    }

    /**
     * @return array<string, Closure>
     */
    public function forUser(?User $user): array
    {
        return [
            'aiPaused' => fn (): bool => $this->aiPausedFor($user),
            'aiPauseRetriesFailed' => fn (): bool => $this->aiPausedFor($user) && $this->gate->pauseReason() !== 'cost_ceiling',
            'aiCatchingUp' => fn (): bool => $this->aiCatchingUpFor($user),
        ];
    }

    /**
     * Whether LLM narration is globally paused right now (cost ceiling, kill switch, unconfigured
     * Azure, or a tripped config circuit), so the UI can show a soft "Temari is resting" banner.
     * Only the pause fact is shared, never the operator-facing reason. Cached globally for a short
     * window since it fires on every page load; guests skip the check.
     *
     * Deliberately the global question, not this athlete's: an athlete past their own per-user
     * ceiling is served from the rule-based filler, which reads normally.
     */
    private function aiPausedFor(?User $user): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        return SharedPropCacheKey::AiPaused->remember(
            null,
            fn (): bool => $this->gate->generationPaused(),
        );
    }

    /**
     * Whether this user has at least one synced activity still waiting on its per-activity
     * narration (a backfill chain has not reached it yet, or a failed attempt is still under retry
     * budget), or narration held behind the still-hydrating history gate ({@see
     * HydrationBacklog::awaitsFullHydration()}), so the UI can show a soft "still catching up"
     * reassurance.
     *
     * `awaitsFullHydration()` is a superset of every narrower hydration hold the pipeline applies,
     * so one check stands in for all of them. A dead-lettered row and a deferred recap `pending`
     * row are deliberately excluded because they will not resolve on their own, and the check is
     * skipped while generation is globally paused ({@see self::aiPausedFor()}).
     */
    private function aiCatchingUpFor(?User $user): bool
    {
        if (!$user instanceof User || $this->aiPausedFor($user)) {
            return false;
        }

        return SharedPropCacheKey::AiCatchingUp->remember(
            $user->id,
            fn (): bool => $this->backlog->awaitsFullHydration($user->id)
                || Analysis::query()
                    ->where('subject_type', Activity::class)
                    ->whereIn('analysis_type', array_column(AnalyzeActivityJob::groupedTypes(), 'value'))
                    ->whereIn('subject_id', Activity::query()->where('user_id', $user->id)->select('id'))
                    ->where(fn (Builder $q) => $q
                        ->whereIn('status', [AnalysisStatus::Queued, AnalysisStatus::Processing])
                        ->orWhere(fn (Builder $q2) => $q2->stalled()))
                    ->exists(),
        );
    }
}
