<?php

declare(strict_types=1);

it('pages a backed-up ai queue at the seven-minute block polling budget', function (): void {
    expect(config('horizon.waits'))->toBe([
        'redis:default' => 60,
        'redis:ai' => 420,
    ]);
})->group('structure');
