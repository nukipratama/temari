<?php

declare(strict_types=1);

namespace App\Services\Run\Story\Narrators;

use App\Models\User;
use App\Services\Run\Story\BriefingResult;
use App\Services\Run\Story\Contracts\BriefingNarrator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tries the primary narrator first (typically LLM). If it throws,
 * logs the error and falls back to the secondary (rule-based) while
 * flipping `degraded = true` so the UI can surface a warning chip.
 */
final readonly class FallbackBriefingNarrator implements BriefingNarrator
{
    public function __construct(
        private BriefingNarrator $primary,
        private BriefingNarrator $secondary,
    ) {
    }

    public function generate(User $user, ?Carbon $asOf = null): BriefingResult
    {
        try {
            return $this->primary->generate($user, $asOf);
        } catch (Throwable $e) {
            Log::warning('narrator.briefing.fail', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            $fallback = $this->secondary->generate($user, $asOf);
            $fallback->degraded = true;

            return $fallback;
        }
    }
}
