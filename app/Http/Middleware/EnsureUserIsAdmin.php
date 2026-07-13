<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * App-level authorization for the ops dashboards (/ai-usage and its mutating
 * retry endpoint). Requires a logged-in user carrying `is_admin` — a
 * per-Strava-account gate that replaces the shared edge basic-auth password and
 * properly authorizes the mutating retry POST. A guest is bounced by the
 * upstream `auth` middleware; a logged-in non-admin gets a 403.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_admin !== true) {
            abort(403);
        }

        return $next($request);
    }
}
