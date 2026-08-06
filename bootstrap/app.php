<?php

use App\Http\Middleware\BlockDemoTelegramWrites;
use App\Http\Middleware\EnsureDevtoolsAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetInertiaEtag;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Cloudflare Tunnel in prod: TLS terminates at the CF edge and
        // cloudflared (on the host) forwards plain HTTP to the container over the
        // docker bridge, so the immediate proxy the app sees is always a private
        // address. Trust only private ranges + loopback — enough to honor
        // X-Forwarded-Proto: https (otherwise Strava OAuth fails with redirect_uri
        // mismatch) without trusting a forwarded header spoofed from a public IP.
        // Laravel's default header set also trusts X-Forwarded-Host and
        // X-Forwarded-Prefix, which the CF edge relays from the client; narrowed
        // to the three this topology needs. Host keeps resolving from the Host
        // header, so the redirect_uri above is unaffected.
        $middleware->trustProxies(at: [
            '127.0.0.0/8',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ], headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PORT);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'block-demo-telegram' => BlockDemoTelegramWrites::class,
            'devtools' => EnsureDevtoolsAccess::class,
            'inertia-etag' => SetInertiaEtag::class,
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
