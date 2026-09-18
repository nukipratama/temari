<?php

declare(strict_types=1);

use App\Services\Run\Story\Card\CardOptions;

it('has every optional fact on by default', function (): void {
    $options = new CardOptions();

    expect($options->heartRate)->toBeTrue()
        ->and($options->elevation)->toBeTrue()
        ->and($options->weather)->toBeTrue()
        ->and($options->badges)->toBeTrue();
});

it('reads the query string\'s boolean spellings', function (): void {
    $options = CardOptions::fromArray([
        'hr' => 'false',
        'elevation' => '0',
        'weather' => 'true',
        'badges' => '1',
    ]);

    expect($options->heartRate)->toBeFalse()
        ->and($options->elevation)->toBeFalse()
        ->and($options->weather)->toBeTrue()
        ->and($options->badges)->toBeTrue();
});

it('keeps a fact on when its parameter is missing or unreadable', function (): void {
    $options = CardOptions::fromArray(['hr' => 'perhaps']);

    expect($options->heartRate)->toBeTrue()
        ->and($options->badges)->toBeTrue();
});

it('gives each combination its own cache key', function (): void {
    expect(new CardOptions()->cacheKey())->toBe('1111')
        ->and(new CardOptions(heartRate: false)->cacheKey())->toBe('0111')
        ->and(new CardOptions(badges: false)->cacheKey())->toBe('1110');
});
