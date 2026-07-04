<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockDemoWrites
{
    /**
     * Makes the shared demo account effectively read-only: any mutating
     * request (POST/PUT/PATCH/DELETE) from `is_demo` is rejected before it
     * reaches the controller, so a visitor's writes never persist and leak
     * into the next visitor's session. Logout and the "Baca ulang" analysis
     * trigger are excluded at the route level (`withoutMiddleware`), not here.
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

        $message = 'Akun demo cuma bisa dilihat, gak bisa diubah.';

        if ($request->header('X-Inertia') === null) {
            return response()->json(['message' => $message], 403);
        }

        return back()->withErrors(['demo' => $message]);
    }
}
