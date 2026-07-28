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
        return 'Perbandingan lari terakhir pengguna dengan lari serupa miliknya di masa lalu. '
            .'pace_diff_sec dan time_diff_sec positif = yang terakhir lebih cepat, negatif = lebih '
            .'pelan. Kalau past_you gak muncul, gak ada tandingan yang layak, dan kalau begitu jangan '
            .'mengarang perbandingan masa lalu.';
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
