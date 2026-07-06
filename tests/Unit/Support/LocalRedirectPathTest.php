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

it('strips embedded control characters (CRLF header-injection defense)', function (): void {
    // parse_url() itself neutralizes control characters inside a component
    // when parsing (this is the actual mechanism the class docblock relies
    // on) — sanitize() only ever reconstructs the result from $parts, never
    // returns the original raw string, so this is a regression guard against
    // ever changing that reconstruction back to returning $path verbatim.
    $path = "/foo\r\nbar";

    $result = LocalRedirectPath::sanitize($path);

    expect($result)->not->toBeNull()
        ->and($result)->not->toContain("\r")
        ->and($result)->not->toContain("\n");
});

it('strips an embedded null byte', function (): void {
    $path = "/foo\0bar";

    $result = LocalRedirectPath::sanitize($path);

    expect($result)->not->toBeNull()
        ->and($result)->not->toContain("\0");
});

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
