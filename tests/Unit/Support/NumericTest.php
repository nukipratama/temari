<?php

declare(strict_types=1);

use App\Support\Numeric;

it('casts numeric values the same way a plain cast does', function (mixed $value, float $float, int $int): void {
    expect(Numeric::float($value))->toBe($float)
        ->and(Numeric::int($value))->toBe($int);
})->with([
    'int' => [7, 7.0, 7],
    'float' => [7.6, 7.6, 7],
    'numeric string' => ['7.6', 7.6, 7],
    'null' => [null, 0.0, 0],
]);

it('rejects a non-numeric value only where assertions run, and casts as before elsewhere', function (): void {
    if (ini_get('zend.assertions') === '1') {
        expect(fn () => Numeric::float('seven'))->toThrow(AssertionError::class);

        return;
    }

    expect(Numeric::float('seven'))->toBe(0.0)
        ->and(Numeric::int('seven'))->toBe(0);
});
