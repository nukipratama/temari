<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockDemoTelegramWrites
{
    /**
     * Guards the Telegram routes against the shared demo account: a demo visitor
     * could disconnect the shared bot or spam real messages, so any mutating
     * request (POST/PUT/PATCH/DELETE) from `is_demo` is rejected before it
     * reaches the controller. Applied only to the Telegram send/connection
     * routes (not blanket) — the rest of the demo is an interactive sandbox.
     * The frontend disables these controls up front, so this is the
     * defense-in-depth net for a direct API call that bypasses the UI.
     *
     * Inertia visits (`router.post`/`patch`/`delete`) get a redirect back with
     * a flashed error so the existing `$errors` bag renders it; plain `fetch`
     * calls (no `X-Inertia` header) get a JSON 403 since Inertia's client
     * cannot parse those bare JSON responses.
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
