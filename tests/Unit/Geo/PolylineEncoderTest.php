<?php

declare(strict_types=1);

use App\Services\Geo\PolylineDecoder;
use App\Services\Geo\PolylineEncoder;

it('encodes the canonical Google example losslessly', function (): void {
    // Google's official polyline-encoding doc reference.
    $encoder = new PolylineEncoder();
    $encoded = $encoder->encode([
        [38.5, -120.2],
        [40.7, -120.95],
        [43.252, -126.453],
    ]);
    expect($encoded)->toBe('_p~iF~ps|U_ulLnnqC_mqNvxq`@');
});

it('round-trips through the decoder for the first point', function (): void {
    $encoder = new PolylineEncoder();
    $decoder = new PolylineDecoder();
    $encoded = $encoder->encode([
        [-6.2253, 106.8090],
        [-6.2203, 106.8100],
    ]);
    $back = $decoder->firstPoint($encoded);
    expect($back)->not->toBeNull();
    expect($back[0])->toEqualWithDelta(-6.2253, 1e-5);
    expect($back[1])->toEqualWithDelta(106.8090, 1e-5);
});

it('returns an empty string for an empty point list', function (): void {
    expect((new PolylineEncoder())->encode([]))->toBe('');
});

it('round-trips a single-point list exactly', function (): void {
    $encoder = new PolylineEncoder();
    $decoder = new PolylineDecoder();

    $encoded = $encoder->encode([[-6.2253, 106.8090]]);
    $points = $decoder->decode($encoded);

    expect($points)->toHaveCount(1)
        ->and($points[0][0])->toEqualWithDelta(-6.2253, 1e-5)
        ->and($points[0][1])->toEqualWithDelta(106.8090, 1e-5);
});

it('rounds a precision tie (exactly .5 at the 1e5 fixed-point scale) consistently', function (): void {
    // 1.234565 * 1e5 lands on a tie (123456.5, modulo float representation),
    // which this PHP build's round() resolves to 123456 (not 123457). Hardcoded
    // rather than re-derived via round() so a future change to the rounding
    // call — or a PHP version with different tie-breaking — doesn't pass silently.
    $encoder = new PolylineEncoder();
    $decoder = new PolylineDecoder();

    $encoded = $encoder->encode([[1.234565, -1.234565]]);
    $points = $decoder->decode($encoded);

    expect($points[0][0])->toBe(1.23456)
        ->and($points[0][1])->toBe(-1.23456);
});
