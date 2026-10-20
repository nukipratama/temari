<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conditional GET for the Inertia page object on the run-history read surface.
 *
 * The ETag is a hash of the exact response bytes, computed after Inertia has
 * serialized the page, so a 304 is only emitted when the browser's copy is
 * byte-identical.
 *
 * Applied per-route, never globally: it only pays off where the same URL is
 * revisited and the tagged bytes are the whole response, which a page behind
 * `Inertia::defer()` fails. See docs/architecture/frontend-architecture.md.
 */
class SetInertiaEtag
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodCacheable() || $request->header('X-Inertia') === null) {
            return $response;
        }

        // A partial reload answers the same URL with a subset of the props, and
        // the browser cache is keyed by URL — storing it would evict the full
        // page object the next visit wants to revalidate against.
        if ($request->header('X-Inertia-Partial-Data') !== null) {
            $response->setCache(['no_store' => true, 'private' => true]);

            return $response;
        }

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            return $response;
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return $response;
        }

        $response->setCache([
            'etag' => hash('xxh128', $content),
            'private' => true,
            'no_cache' => true,
        ]);

        $response->isNotModified($request);

        return $response;
    }
}
