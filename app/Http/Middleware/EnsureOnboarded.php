<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboarded
{
    /**
     * Routes the wizard itself needs to call before the user is onboarded.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTE_NAMES = [
        'push.subscribe',
        'push.unsubscribe',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null && $user->onboarded_at === null && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTE_NAMES, true)) {
            return redirect()->route('onboarding.show');
        }

        return $next($request);
    }
}
