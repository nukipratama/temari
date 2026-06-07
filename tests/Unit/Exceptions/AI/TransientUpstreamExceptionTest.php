<?php

declare(strict_types=1);

use App\Exceptions\AI\TransientUpstreamException;

it('is a runtime exception so unhandled instances still surface', function (): void {
    expect(new TransientUpstreamException('boom'))->toBeInstanceOf(RuntimeException::class);
});

it('defaults retryAfterSeconds to null when no upstream hint is given', function (): void {
    $e = new TransientUpstreamException('rate limited');

    expect($e->getMessage())->toBe('rate limited')
        ->and($e->retryAfterSeconds)->toBeNull();
});

it('carries the Retry-After delay and the previous throwable', function (): void {
    $previous = new RuntimeException('original');
    $e = new TransientUpstreamException('rate limited', retryAfterSeconds: 42, previous: $previous);

    expect($e->retryAfterSeconds)->toBe(42)
        ->and($e->getPrevious())->toBe($previous);
});
