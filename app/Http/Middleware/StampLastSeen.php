<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records that an athlete opened the app today, which is what
 * {@see \App\Actions\AI\RecentlyActiveUsers} spends scheduled narration on.
 *
 * At most one write per athlete per calendar day: the comparison is on the date,
 * not the timestamp, so a session of fifty requests costs one UPDATE. The demo
 * identity is skipped — it is shared and public, and it is excluded from every
 * billing cadence anyway.
 */
class StampLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_demo && ! $user->last_seen_at?->isSameDay(Carbon::today())) {
            $user->forceFill(['last_seen_at' => Carbon::now()])->save();
        }

        return $next($request);
    }
}
