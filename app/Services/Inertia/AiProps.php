<?php

declare(strict_types=1);

namespace App\Services\Inertia;

use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Support\SharedPropCacheKey;
use Closure;

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
        ];
    }

    /**
     * Whether LLM narration is globally paused right now (cost ceiling, kill
     * switch, unconfigured Azure, or a tripped config circuit), so the UI can show
     * a soft "Temari lagi istirahat" banner instead of scattered silent empty
     * states. Only the pause *fact* is shared, never the operator-facing reason
     * (that stays maintainer-only via the Telegram alert + /ai-usage). Cached
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
}
