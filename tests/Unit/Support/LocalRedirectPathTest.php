<?php

declare(strict_types=1);

use App\Support\LocalRedirectPath;

it('keeps a same-host relative path', function (string $path): void {
    expect(LocalRedirectPath::sanitize($path))->toBe($path);
})->with([
    '/aktivitas/5',
    '/aktivitas/5?tab=splits',
    '/',
]);

it('rejects anything that is not a bare relative path', function (?string $path): void {
    expect(LocalRedirectPath::sanitize($path))->toBeNull();
})->with([
    'null' => null,
    'absolute http' => 'http://evil.test/aktivitas/5',
    'absolute https' => 'https://evil.test/aktivitas/5',
    'protocol relative' => '//evil.test/aktivitas/5',
    'no leading slash' => 'aktivitas/5',
    'backslash smuggle' => '/\\evil.test',
]);

it('reduces a full same-host intended URL to its path', function (): void {
    expect(LocalRedirectPath::fromIntended(url('/aktivitas/5?tab=splits')))
        ->toBe('/aktivitas/5?tab=splits');
});

it('accepts an already-relative intended value', function (): void {
    expect(LocalRedirectPath::fromIntended('/aktivitas/5'))->toBe('/aktivitas/5');
});

it('drops an intended URL pointing at another host', function (): void {
    expect(LocalRedirectPath::fromIntended('https://evil.test/aktivitas/5'))->toBeNull();
});

it('returns null for a null intended value', function (): void {
    expect(LocalRedirectPath::fromIntended(null))->toBeNull();
});
