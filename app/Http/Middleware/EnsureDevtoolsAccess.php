<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class EnsureDevtoolsAccess
{
    private const string REALM = 'Devtools';

    private const int MAX_FAILED_ATTEMPTS = 5;

    private const int LOCKOUT_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local')) {
            return $next($request);
        }

        $configuredPassword = config('devtools.password');
        $password = is_string($configuredPassword) ? $configuredPassword : '';
        $attempt = (string) $request->getPassword();
        $limiterKey = 'devtools-auth:'.$request->ip();

        if ($password === '') {
            return $this->challenge();
        }

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_FAILED_ATTEMPTS)) {
            return response('Too Many Attempts', 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($limiterKey));
        }

        if (hash_equals($password, $attempt)) {
            return $next($request);
        }

        if ($attempt !== '') {
            RateLimiter::hit($limiterKey, self::LOCKOUT_SECONDS);
        }

        return $this->challenge();
    }

    private function challenge(): Response
    {
        return response('Unauthorized', 401)
            ->header('WWW-Authenticate', 'Basic realm="'.self::REALM.'"');
    }
}
