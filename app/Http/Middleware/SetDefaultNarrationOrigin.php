<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every authenticated web request is user-initiated unless the controller
 * says otherwise, so this stamps {@see AnalysisOrigin::User} once here
 * instead of at the top of every controller action that dispatches a
 * narration. A route whose origin is NOT the default (a webhook, a devtools
 * re-arm) still declares itself explicitly, and that explicit call wins
 * because it runs after this middleware.
 */
class SetDefaultNarrationOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null) {
            app(NarrationOrigin::class)->set(AnalysisOrigin::User);
        }

        return $next($request);
    }
}
