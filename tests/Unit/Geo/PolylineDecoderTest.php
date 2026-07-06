<?php

declare(strict_types=1);

use App\Services\Geo\PolylineDecoder;

it('decodes the first point of a Google-encoded polyline', function (): void {
    // Canonical Google example; first point = (38.5, -120.2).
    $point = (new PolylineDecoder())->firstPoint('_p~iF~ps|U_ulLnnqC_mqNvxq`@');
    expect($point)->not->toBeNull();
    expect($point[0])->toEqualWithDelta(38.5, 0.0001);
    expect($point[1])->toEqualWithDelta(-120.2, 0.0001);
});

it('decodes every point of a Google-encoded polyline', function (): void {
    // Canonical Google example → 3 points.
    $points = (new PolylineDecoder())->decode('_p~iF~ps|U_ulLnnqC_mqNvxq`@');
    expect($points)->toHaveCount(3);
    expect($points[0][0])->toEqualWithDelta(38.5, 0.0001);
    expect($points[0][1])->toEqualWithDelta(-120.2, 0.0001);
    expect($points[2][0])->toEqualWithDelta(43.252, 0.0001);
    expect($points[2][1])->toEqualWithDelta(-126.453, 0.0001);
});

it('decodes an empty polyline to an empty list', function (): void {
    expect((new PolylineDecoder())->decode(''))->toBe([]);
});

it('returns null for an empty polyline', function (): void {
    expect((new PolylineDecoder())->firstPoint(''))->toBeNull();
});

it('returns null when the latitude chunk is truncated', function (): void {
    expect((new PolylineDecoder())->firstPoint('_'))->toBeNull();
});

it('returns null when only the latitude chunk is present', function (): void {
    expect((new PolylineDecoder())->firstPoint('_p~iF'))->toBeNull();
});

it('returns the points decoded so far when the stream truncates mid-point after a valid first point', function (): void {
    // A complete first-point encoding, then one dangling continuation byte
    // ('~' has its 0x20 continuation bit set) that never terminates — decode()
    // must return the one point it already had, not crash or drop it too.
    $points = (new PolylineDecoder())->decode('_p~iF~ps|U' . '~');

    expect($points)->toHaveCount(1)
        ->and($points[0][0])->toEqualWithDelta(38.5, 0.0001)
        ->and($points[0][1])->toEqualWithDelta(-120.2, 0.0001);
});
