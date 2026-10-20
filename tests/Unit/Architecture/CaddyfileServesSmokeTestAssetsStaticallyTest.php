<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * The deploy smoke test fetches these PWA assets and rolls back on any failure.
 * They stay reachable during maintenance only because Caddy answers them from
 * disk and never hands them to Laravel, where the maintenance page would 503.
 */
it('serves each deploy smoke-test asset as a static file, never through PHP', function (string $path): void {
    $caddyfile = (string) File::get(base_path('docker/Caddyfile'));

    expect(preg_match('/^\s*@(\S+) path [^\n]*(?<=\s)'.preg_quote($path, '/').'(?=\s|$)/m', $caddyfile, $matcher))
        ->toBe(1, "{$path} has no path matcher in docker/Caddyfile");

    $matcherName = $matcher[1] ?? throw new InvalidArgumentException("{$path} has no path matcher name.");

    expect(preg_match('/handle @'.preg_quote($matcherName, '/').' \{([^}]*)\}/', $caddyfile, $block))->toBe(1);

    $body = $block[1] ?? throw new InvalidArgumentException("{$path} has no handle block.");

    expect($body)->toContain('file_server')
        ->and($body)->not->toContain('php_server')
        ->and(File::exists(public_path(ltrim($path, '/'))))->toBeTrue();
})->with(['/sw.js', '/manifest.webmanifest', '/offline.html'])->group('structure');
