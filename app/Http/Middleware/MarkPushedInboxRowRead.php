<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks the inbox row a tapped push named (`?read=<id>`, appended by
 * public/sw.js) read before the page renders, so the badge and the bell in that
 * very response already count it as seen. Scoped to the athlete's own rows.
 */
class MarkPushedInboxRowRead
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->query('read');
        $user = $request->user();

        if ($request->isMethod('GET') && $user !== null && is_string($id) && ctype_digit($id)) {
            $user->inboxNotifications()->find((int) $id)?->markRead();
        }

        return $next($request);
    }
}
