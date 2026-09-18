<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * The share card is drawn in the browser and previewed from a `blob:` URL
 * (see resources/js/lib/card/print.ts). Production's CSP is only applied by
 * docker/Caddyfile — local Sail never sends this header, so a regression here
 * fails silently until a real deploy blocks every print. #983 was exactly
 * this: `img-src` allowed `data: https:` but not `blob:`.
 */
it('allows blob: images in every img-src directive of the Caddyfile', function (): void {
    $caddyfile = (string) File::get(base_path('docker/Caddyfile'));

    preg_match_all('/img-src ([^;]+);/', $caddyfile, $matches);

    expect($matches[1])->not->toBeEmpty();

    $missing = array_values(array_filter(
        $matches[1],
        fn (string $sources): bool => ! str_contains($sources, 'blob:'),
    ));

    expect($missing)->toBe(
        [],
        'img-src directive(s) without blob: in docker/Caddyfile: '.implode(', ', $missing),
    );
})->group('structure');
