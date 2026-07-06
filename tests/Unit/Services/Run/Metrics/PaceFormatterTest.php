<?php

declare(strict_types=1);

use App\Services\Run\Metrics\PaceFormatter;

it('formats pace as M:SS', function (): void {
    expect(PaceFormatter::format(300.0))->toBe('5:00')
        ->and(PaceFormatter::format(359.0))->toBe('5:59');
});

it('rounds fractional seconds to the nearest whole second', function (): void {
    expect(PaceFormatter::format(300.4))->toBe('5:00')
        ->and(PaceFormatter::format(300.6))->toBe('5:01');
});

it('formats zero pace as 0:00', function (): void {
    expect(PaceFormatter::format(0.0))->toBe('0:00');
});

it('parses an M:SS label back into seconds', function (): void {
    expect(PaceFormatter::parse('5:00'))->toBe(300.0)
        ->and(PaceFormatter::parse('5:59'))->toBe(359.0);
});

it('round-trips format() through parse()', function (): void {
    expect(PaceFormatter::parse(PaceFormatter::format(300.0)))->toBe(300.0);
});

it('returns null for a malformed label', function (?string $label): void {
    expect(PaceFormatter::parse($label))->toBeNull();
})->with([
    'not a pace at all' => ['not a pace'],
    'missing seconds' => ['5:0'],
    'missing colon' => ['500'],
    'hour-scale label' => ['1:05:00'],
]);
