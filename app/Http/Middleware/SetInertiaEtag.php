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
 * already serialized the page object — so it evaluates no extra props and a 304
 * is only ever emitted when the copy the browser holds is byte-identical to the
 * one this request just built. That makes it self-correcting: the auth block,
 * the flash bag and every shared prop are inside the hash, so a change to any
 * of them is a miss, and two users can never collide on an ETag because their
 * `auth.user.id` differs. `private` keeps the body out of shared caches and
 * `no-cache` forces revalidation on every use, so no intermediary may answer
 * from its own store.
 *
 * Applied per-route, never globally: it saves wire bytes, not server work, so
 * it only pays off where the same URL is genuinely revisited.
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
