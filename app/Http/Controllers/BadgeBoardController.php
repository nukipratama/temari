<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Gamification\GrantSeasonUnlocksAction;
use App\Enums\Badge;
use App\Models\PlannedSession;
use App\Models\RunCard;
use App\Models\User;
use App\Models\UserUnlock;
use App\Services\Gamification\SeasonGamificationContext;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Plan\SeasonService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The badge board: all 16 surviving {@see Badge} cases plus the rest-day
 * reward (see {@see GrantSeasonUnlocksAction}'s docblock for why that one
 * isn't a `Badge` case) as one visually-equivalent list. Earned entries show
 * a lifetime count; unearned show their criterion (the frontend already owns
 * that text via `runcard.ts`'s `BADGE_LABELS`/`BADGE_ABILITY`, so this
 * controller only ships keys and counts). Every entry also carries a
 * "this season" count, scoped to the active {@see \App\Models\Season}.
 */
class BadgeBoardController extends Controller
{
    public function index(
        Request $request,
        SeasonService $seasonService,
        TrainingLoad $trainingLoad,
        GrantSeasonUnlocksAction $grantSeasonUnlocks,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $today = Carbon::today();

        $season = $seasonService->ensureCurrent($user, $today);
        $seasonCtx = SeasonGamificationContext::forSeason($user, $season, $today, $trainingLoad);
        $grantSeasonUnlocks($user, $season, $seasonCtx);

        $lifetimeCounts = RunCard::allBadgeCountsForUser($user->id);
        $seasonCounts = RunCard::allBadgeCountsForUser($user->id, $season->starts_at, $season->ends_at);

        $items = [];
        foreach (Badge::cases() as $badge) {
            $items[] = [
                'key' => $badge->value,
                'unlocked' => $lifetimeCounts[$badge->value] > 0,
                'lifetime_count' => $lifetimeCounts[$badge->value],
                'season_count' => $seasonCounts[$badge->value],
            ];
        }

        $everHonored = UserUnlock::query()
            ->where('user_id', $user->id)
            ->where('unlock_key', 'like', 'season.%.rest_honored_%')
            ->exists();

        $items[] = [
            'key' => 'season.rest_honored',
            'unlocked' => $everHonored,
            'lifetime_count' => PlannedSession::restHonoredCountForUser($user->id, $today),
            'season_count' => $seasonCtx->restHonored,
        ];

        return Inertia::render('Collection/Badges', [
            'items' => $items,
            'seasonStartsAt' => $season->starts_at->toDateString(),
            'seasonEndsAt' => $season->ends_at->toDateString(),
        ]);
    }
}
