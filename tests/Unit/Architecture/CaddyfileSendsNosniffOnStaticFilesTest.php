<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('sends nosniff on every statically served path', function (string $matcherName): void {
    $caddyfile = (string) File::get(base_path('docker/Caddyfile'));

    expect(preg_match('/handle @'.preg_quote($matcherName, '/').' \{([^}]*)\}/', $caddyfile, $block))->toBe(1);

    $body = $block[1] ?? throw new InvalidArgumentException("@{$matcherName} has no handle block.");

    expect($body)->toContain('header X-Content-Type-Options "nosniff"')
        ->and($body)->toContain('file_server');
})->with(['vite', 'static', 'sw', 'manifest'])->group('structure');
