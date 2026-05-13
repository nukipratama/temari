<?php

declare(strict_types=1);

namespace App\Services\Run\Story\Narrators;

use App\Models\User;
use App\Services\Run\Story\BriefingResult;
use App\Services\Run\Story\Contracts\BriefingNarrator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Caches BriefingResult per (user, date) so repeated dashboard loads
 * inside the TTL window don't re-hit the LLM. Carries the `degraded`
 * flag across cache hits — if the original generation fell back, the
 * cached copy still surfaces that signal to the UI.
 */
final readonly class CachingBriefingNarrator implements BriefingNarrator
{
    public function __construct(
        private BriefingNarrator $inner,
        private int $ttlSeconds = 3600,
    ) {
    }

    public function generate(User $user, ?Carbon $asOf = null): BriefingResult
    {
        $asOf ??= Carbon::today();
        $key = sprintf('narrator:briefing:%d:%s', $user->id, $asOf->toDateString());

        $cached = Cache::get($key);
        if ($cached instanceof BriefingResult) {
            return $cached;
        }

        $result = $this->inner->generate($user, $asOf);
        Cache::put($key, $result, $this->ttlSeconds);

        return $result;
    }
}
