<?php

declare(strict_types=1);

use App\Exceptions\AI\RejectedOutputException;
use App\Exceptions\AI\UnavailableException;

it('extends UnavailableException so any uncaught path stays terminal', function (): void {
    expect(new RejectedOutputException('rejected twice'))
        ->toBeInstanceOf(UnavailableException::class)
        ->toBeInstanceOf(RuntimeException::class);
});
