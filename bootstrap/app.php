<?php

use App\Http\Middleware\BlockDemoTelegramWrites;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Cloudflare Tunnel in prod: TLS terminates at the CF edge and
        // cloudflared forwards plain HTTP with X-Forwarded-Proto: https.
        // Trust all proxies so Laravel honors the header and generates https
        // URLs (otherwise Strava OAuth fails with redirect_uri mismatch).
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'block-demo-telegram' => BlockDemoTelegramWrites::class,
            'admin' => EnsureUserIsAdmin::class,
        ]);

        // Strava POSTs the webhook with no session/CSRF token; it is guarded by
        // the verify token + athlete scoping in the controller instead.
        // client-errors is exempt too: it's low-risk telemetry guarded by an IP
        // rate limiter, and a global JS error handler may fire without a token.
        $middleware->validateCsrfTokens(except: [
            'strava/webhook',
            'telegram/webhook',
            'client-errors',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
