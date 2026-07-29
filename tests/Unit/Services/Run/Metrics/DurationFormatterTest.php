<?php

declare(strict_types=1);

use App\Services\Run\Metrics\DurationFormatter;

it('formats a sub-hour duration as M:SS', function (): void {
    expect(DurationFormatter::hms(0))->toBe('0:00')
        ->and(DurationFormatter::hms(59))->toBe('0:59')
        ->and(DurationFormatter::hms(600))->toBe('10:00')
        ->and(DurationFormatter::hms(3599))->toBe('59:59');
});

it('formats an hour or more as H:MM:SS', function (): void {
    expect(DurationFormatter::hms(3600))->toBe('1:00:00')
        ->and(DurationFormatter::hms(3661))->toBe('1:01:01')
        ->and(DurationFormatter::hms(45296))->toBe('12:34:56');
});
