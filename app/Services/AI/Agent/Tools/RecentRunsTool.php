<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\User;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\VerdictTimelineItem;
use Illuminate\Support\Carbon;

final class RecentRunsTool extends UserTool
{
    private const int LIMIT = 5;

    public function __construct(
        User $user,
        Carbon $asOf,
        private readonly VerdictNarrator $verdicts,
    ) {
        parent::__construct($user, $asOf);
    }

    public function name(): string
    {
        return 'get_recent_runs';
    }

    public function description(): string
    {
        return "The user's last five runs with mood, distance, intensity, and a one-line summary "
            .'each. Call this when you want to connect to what they\'ve just been doing.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $runs = array_map(
            fn (VerdictTimelineItem $item): array => [
                'mood' => $item->mood,
                'km' => $item->distanceKm,
                'intensity' => $item->intensity,
                'oneline' => $item->oneline,
            ],
            // recent() applies the limit in SQL, so no second slice is needed.
            $this->verdicts->recent($this->user, self::LIMIT),
        );

        return ['recent_runs' => $runs];
    }
}
