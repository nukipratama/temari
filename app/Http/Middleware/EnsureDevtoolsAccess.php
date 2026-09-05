<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDevtoolsAccess
{
    private const string REALM = 'Devtools';

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isProduction()) {
            return $next($request);
        }

        $password = (string) config('devtools.password');

        if ($password !== '' && hash_equals($password, (string) $request->getPassword())) {
            return $next($request);
        }

        return response('Unauthorized', 401)
            ->header('WWW-Authenticate', 'Basic realm="'.self::REALM.'"');
    }
}
