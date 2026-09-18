<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * #1045: opcache JIT miscompiled code inside the long-lived FrankenPHP worker.
 * docker/php.ini is baked into the prod image (COPY'd to zz-app.ini in the
 * Dockerfile) — a regression here silently brings JIT back on the next build.
 */
it('disables JIT while keeping opcache enabled in the production php.ini', function (): void {
    $ini = (string) File::get(base_path('docker/php.ini'));

    expect($ini)->toMatch('/^opcache\.enable\s*=\s*1$/m')
        ->and($ini)->toMatch('/^opcache\.jit\s*=\s*disable$/m')
        ->and($ini)->toMatch('/^opcache\.jit_buffer_size\s*=\s*0$/m');
})->group('structure');
