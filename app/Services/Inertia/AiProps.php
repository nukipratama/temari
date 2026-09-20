<?php

declare(strict_types=1);

namespace App\Services\Inertia;

use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\HistoryNarrationGate;
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
        private AnalysisService $analyses,
        private HistoryNarrationGate $history,
    ) {
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
     * (that stays maintainer-only via the Telegram alert + /devtools/narration). Cached
     * globally for a short window since it fires on every page load; guests never
     * see it, so the check is skipped for them.
     *
     * Deliberately still the GLOBAL question, not this athlete's. An athlete past
     * their own per-user ceiling has narration served from the rule-based filler,
     * which reads normally — "Temari is resting" would be a false explanation for
     * a screen that is filled in, and the cache key is global besides.
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
     * failed attempt is still under retry budget), or has narration held
     * behind the still-hydrating history gate ({@see HistoryNarrationGate::awaitsFullHydration()}),
     * so the UI can show a soft "still catching up" reassurance instead of an
     * empty-looking run. `awaitsFullHydration()` is the widest of the gate's
     * checks — any unhydrated run at any age, not just one narrator's bounded
     * reach — which makes it a superset of every narrower hold
     * (`awaitsOlderHydration()`, per-run `awaitsHydration()`) the pipeline
     * applies to this athlete, so this one check stands in for "is this
     * athlete's narration held by hydration" without restating any of them.
     * It carries its own grace-window cap, so a long-connected athlete or a
     * stuck drain past that window is unaffected. A dead-lettered row (retry
     * budget exhausted, needs a manual re-arm on /devtools/narration) is
     * deliberately excluded — it will not resolve on its own, so counting it
     * here would make the banner a false promise. A deferred recap `pending`
     * row for an open week or month is excluded the same way: it is not a
     * `queued`/`processing`/stalled row and the hydration gate does not
     * govern it, so it never lights this banner. Skipped entirely while
     * generation is globally paused: {@see self::aiPausedFor()} already
     * explains that case.
     */
    private function aiCatchingUpFor(?User $user): bool
    {
        if ($user === null || $this->aiPausedFor($user)) {
            return false;
        }

        return SharedPropCacheKey::AiCatchingUp->remember(
            $user->id,
            fn (): bool => $this->history->awaitsFullHydration($user->id)
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
