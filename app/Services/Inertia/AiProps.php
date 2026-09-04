<?php

declare(strict_types=1);

namespace App\Services\Inertia;

use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
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
    public function __construct(private AnalysisService $analyses)
    {
    }

    /**
     * @return array<string, Closure>
     */
    public function forUser(?User $user): array
    {
        return [
            'aiPaused' => fn (): bool => $this->aiPausedFor($user),
            'aiCatchingUp' => fn (): bool => $this->aiCatchingUpFor($user),
        ];
    }

    /**
     * Whether LLM narration is globally paused right now (cost ceiling, kill
     * switch, unconfigured Azure, or a tripped config circuit), so the UI can show
     * a soft "Temari is resting" banner instead of scattered silent empty
     * states. Only the pause *fact* is shared, never the operator-facing reason
     * (that stays maintainer-only via the Telegram alert + /devtools/ai-usage). Cached
     * globally for a short window since it fires on every page load; guests never
     * see it, so the check is skipped for them.
     */
    private function aiPausedFor(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return SharedPropCacheKey::AiPaused->remember(
            null,
            fn (): bool => $this->analyses->generationPaused(),
        );
    }

    /**
     * Whether this user has at least one synced activity still waiting on its
     * per-activity narration (a backfill chain hasn't reached it yet, or a
     * failed attempt is still under retry budget), so the UI can show a soft
     * "still catching up" reassurance instead of an empty-looking run. A
     * dead-lettered row (retry budget exhausted, needs a manual re-arm on
     * /devtools/ai-usage) is deliberately excluded — it will not resolve on its own, so
     * counting it here would make the banner a false promise. Skipped
     * entirely while generation is globally paused: {@see self::aiPausedFor()}
     * already explains that case.
     */
    private function aiCatchingUpFor(?User $user): bool
    {
        if ($user === null || $this->aiPausedFor($user)) {
            return false;
        }

        return SharedPropCacheKey::AiCatchingUp->remember(
            $user->id,
            fn (): bool => Analysis::query()
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
