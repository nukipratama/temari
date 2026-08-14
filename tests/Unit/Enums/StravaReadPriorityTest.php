<?php

declare(strict_types=1);

use App\Enums\StravaReadPriority;

it('names only the two tiers the read budget is split into', function (): void {
    expect(array_map(fn (StravaReadPriority $p): string => $p->value, StravaReadPriority::cases()))
        ->toBe(['live', 'background']);
});

it('answers isLive only for the live tier', function (): void {
    expect(StravaReadPriority::Live->isLive())->toBeTrue()
        ->and(StravaReadPriority::Background->isLive())->toBeFalse();
});

it('gives each tier its own queue throttle key so one cannot release the other', function (): void {
    expect(StravaReadPriority::Live->throttleKey())->toBe('strava-ingest:live')
        ->and(StravaReadPriority::Background->throttleKey())->toBe('strava-ingest:background')
        ->and(StravaReadPriority::Live->throttleKey())
        ->not->toBe(StravaReadPriority::Background->throttleKey());
});
