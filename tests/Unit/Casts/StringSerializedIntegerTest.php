<?php

declare(strict_types=1);

use App\Casts\StringSerializedInteger;
use App\Models\Activity;

it('reads and writes the value as an integer', function (): void {
    $cast = new StringSerializedInteger();
    $model = new Activity();

    expect($cast->get($model, 'id', '9007199254740993', []))->toBe(9_007_199_254_740_993)
        ->and($cast->set($model, 'id', '9007199254740993', []))->toBe(9_007_199_254_740_993)
        ->and($cast->get($model, 'id', null, []))->toBeNull()
        ->and($cast->set($model, 'id', null, []))->toBeNull();
});

it('serializes the value as a string', function (): void {
    $cast = new StringSerializedInteger();
    $model = new Activity();

    expect($cast->serialize($model, 'id', 9_223_372_036_854_775_807, []))->toBe('9223372036854775807')
        ->and($cast->serialize($model, 'id', null, []))->toBeNull();
});
