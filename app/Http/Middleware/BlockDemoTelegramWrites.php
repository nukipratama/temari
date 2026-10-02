<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockDemoTelegramWrites
{
    /**
     * Blocks mutating requests from the shared demo account on routes that opt in.
     *
     * Inertia visits get a redirect with a flashed error; plain fetch calls get a
     * JSON 403 response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->is_demo !== true || $request->isMethod('GET')) {
            return $next($request);
        }

        $message = 'The demo account is read-only. Nothing here can be changed.';

        if ($request->header('X-Inertia') === null) {
            return response()->json(['message' => $message], 403);
        }

        return back()->withErrors(['demo' => $message]);
    }
}
