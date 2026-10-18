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

it('rejects a non-numeric value where assertions run', function (): void {
    Numeric::float('seven');
})->throws(AssertionError::class);
