<?php

declare(strict_types=1);

use App\Services\Geo\PolylineDecoder;
use App\Services\Geo\PolylineEncoder;

it('encodes the canonical Google example losslessly', function (): void {
    // Three points from Google's official polyline-encoding doc — the
    // reference encoding is `_p~iF~ps|U_ulLnnqC_mqNvxq`@`.
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
