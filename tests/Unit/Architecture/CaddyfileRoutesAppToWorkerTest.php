<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('sends every php_server request that is not a file to the worker script, not index.php', function (): void {
    $caddyfile = (string) File::get(base_path('docker/Caddyfile'));

    $servers = preg_match_all('/^\t+php_server\b/m', $caddyfile);

    expect($caddyfile)->toContain('file /var/www/html/public/frankenphp-worker.php')
        ->and($servers)->toBeGreaterThan(0)
        ->and(substr_count($caddyfile, "php_server {\n\t\t\tindex frankenphp-worker.php\n\t\t\ttry_files {path} frankenphp-worker.php\n\t\t}"))->toBe($servers);
})->group('structure');
