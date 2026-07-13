<?php

declare(strict_types=1);

use App\Exceptions\AI\ContentFilterException;
use App\Exceptions\AI\UnavailableException;

it('extends UnavailableException so any uncaught path stays terminal', function (): void {
    expect(new ContentFilterException('blocked'))
        ->toBeInstanceOf(UnavailableException::class)
        ->toBeInstanceOf(RuntimeException::class);
});

it('carries the message and the previous throwable', function (): void {
    $previous = new RuntimeException('azure 400');
    $e = new ContentFilterException('blocked', previous: $previous);

    expect($e->getMessage())->toBe('blocked')
        ->and($e->getPrevious())->toBe($previous);
});
