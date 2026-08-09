<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Story\PastYouMatcher;
use Illuminate\Support\Carbon;

/**
 * "You then vs you now" for the runner's most recent run, for the narrators
 * that speak about a day rather than about one run.
 */
final class LatestPastYouTool extends UserTool
{
    public function __construct(
        User $user,
        Carbon $asOf,
        private readonly PastYouMatcher $pastYou,
    ) {
        parent::__construct($user, $asOf);
    }

    public function name(): string
    {
        return 'get_latest_past_you';
    }

    public function description(): string
    {
        return "A comparison between the user's most recent run and a similar run of theirs from the "
            .'past. pace_diff_sec and time_diff_sec positive = the recent one is faster, negative = '
            .'slower. If past_you is missing, there\'s no good match, and in that case never make up '
            .'a comparison to the past.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        // Bounded by as-of so a backdated recompute reads the run that was
        // latest that day, not one that came after.
        $detail = ActivityDetail::query()
            ->whereHas('activity', fn ($query) => $query->where('user_id', $this->user->id))
            ->whereNotNull('start_date_local')
            ->where('start_date_local', '<=', $this->asOf->copy()->endOfDay())
            ->orderByDesc('start_date_local')
            ->with('activity')
            ->first();

        if ($detail === null) {
            return ['past_you' => null];
        }

        return ['past_you' => $this->pastYou->findMatchContext($detail->activity, $detail)];
    }
}
