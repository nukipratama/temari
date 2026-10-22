<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Laravel\Pulse\Facades\Pulse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records each request's peak PHP heap per route into Pulse, the measure
 * `memory_limit` is sized against.
 */
final class RecordRequestPeakMemory
{
    public function handle(Request $request, Closure $next): Response
    {
        memory_reset_peak_usage();

        return $next($request);
    }

    public function terminate(Request $request): void
    {
        $route = $request->route();
        $key = $route instanceof Route ? $request->method().' '.$route->uri() : 'unmatched';

        Pulse::record('peak_memory_request', $key, memory_get_peak_usage(true))->max()->avg()->count();
    }
}
