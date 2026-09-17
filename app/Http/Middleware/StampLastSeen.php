<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\AI\RecentlyActiveUsers;
use App\Jobs\AI\NarrateOnReturnJob;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records that an athlete opened the app today, which is what
 * {@see RecentlyActiveUsers} spends narration on, and queues the catch-up for
 * an athlete coming back after the active window lapsed.
 *
 * At most one write per athlete per calendar day: the comparison is on the date,
 * not the timestamp, so a session of fifty requests costs one UPDATE. The demo
 * identity is skipped — it is shared and public, and it is excluded from every
 * billing cadence anyway.
 */
class StampLastSeen
{
    public function __construct(private readonly RecentlyActiveUsers $activeUsers)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_demo && ! $user->last_seen_at?->isSameDay(Carbon::today())) {
            $returning = $this->isReturning($user);

            $user->forceFill(['last_seen_at' => Carbon::now()])->save();

            if ($returning) {
                NarrateOnReturnJob::dispatch($user->id);
            }
        }

        return $next($request);
    }

    /**
     * An account younger than the window has nothing deferred yet: its first
     * connect's backfill owns that history.
     */
    private function isReturning(User $user): bool
    {
        return ! $this->activeUsers->includes($user)
            && $user->created_at?->lt(RecentlyActiveUsers::windowStart()) === true;
    }
}
