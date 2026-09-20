<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** Serves maintenance after the session starts so authenticated admins remain identifiable. */
class EnforceMaintenanceMode
{
    public const int RETRY_AFTER_SECONDS = 300;

    /**
     * Admin sign-in, OAuth and Strava's enqueue-only webhook stay reachable.
     *
     * @var list<string>
     */
    private const array REACHABLE_ROUTES = [
        'login',
        'auth.strava.redirect',
        'auth.strava.callback',
        'strava.webhook.verify',
        'strava.webhook.handle',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs(...self::REACHABLE_ROUTES)) {
            return $next($request);
        }

        if (! app()->isDownForMaintenance() || $request->user()?->is_admin === true) {
            return $next($request);
        }

        if ($request->header('X-Inertia') !== null) {
            return Inertia::location($request->fullUrl());
        }

        $headers = ['Retry-After' => (string) self::RETRY_AFTER_SECONDS];

        if ($request->expectsJson()) {
            return response()->json(['message' => 'temari is under maintenance.'], Response::HTTP_SERVICE_UNAVAILABLE, $headers);
        }

        return response()->view('maintenance', [], Response::HTTP_SERVICE_UNAVAILABLE, $headers);
    }
}
