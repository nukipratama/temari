<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the maintenance page to everyone but admins while maintenance is on.
 *
 * Runs in the `web` group, after the session starts, because it needs to know
 * who is asking; Laravel's global PreventRequestsDuringMaintenance runs before
 * that and would lock the admin out of the Pulse toggle that lifts it.
 */
class EnforceMaintenanceMode
{
    public const int RETRY_AFTER_SECONDS = 300;

    /**
     * Admin sign-in (the Strava callback refuses new athletes itself) and the
     * webhooks, which only enqueue work that drains once maintenance lifts.
     *
     * @var list<string>
     */
    private const array REACHABLE_ROUTES = [
        'login',
        'auth.strava.redirect',
        'auth.strava.callback',
        'strava.webhook.verify',
        'strava.webhook.handle',
        'telegram.webhook.handle',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isDownForMaintenance()
            || $request->user()?->is_admin === true
            || $request->routeIs(...self::REACHABLE_ROUTES)
            || self::isDevtoolsRoute($request)) {
            return $next($request);
        }

        if ($request->header('X-Inertia') !== null) {
            return Inertia::location($request->fullUrl());
        }

        $headers = ['Retry-After' => (string) self::RETRY_AFTER_SECONDS];

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Temari is under maintenance.'], Response::HTTP_SERVICE_UNAVAILABLE, $headers);
        }

        return response()->view('maintenance', [], Response::HTTP_SERVICE_UNAVAILABLE, $headers);
    }

    /**
     * Pulse, Horizon, their Livewire endpoint and /devtools keep their own
     * devtools password gate, so the toggle stays reachable even without an
     * admin session.
     */
    private static function isDevtoolsRoute(Request $request): bool
    {
        $route = $request->route();

        return $route instanceof Route
            && in_array(EnsureDevtoolsAccess::class, app(Router::class)->gatherRouteMiddleware($route), true);
    }
}
