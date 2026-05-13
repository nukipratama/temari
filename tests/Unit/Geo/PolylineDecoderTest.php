<?php

declare(strict_types=1);

use App\Services\Geo\PolylineDecoder;

it('decodes the first point of a Google-encoded polyline', function (): void {
    // `_p~iF~ps|U_ulLnnqC_mqNvxq`@` is the canonical Google example, first
    // point = (38.5, -120.2).
    $point = (new PolylineDecoder())->firstPoint('_p~iF~ps|U_ulLnnqC_mqNvxq`@');
    expect($point)->not->toBeNull();
    expect($point[0])->toEqualWithDelta(38.5, 0.0001);
    expect($point[1])->toEqualWithDelta(-120.2, 0.0001);
});

it('returns null for an empty polyline', function (): void {
    expect((new PolylineDecoder())->firstPoint(''))->toBeNull();
});

it('returns null when the latitude chunk is truncated', function (): void {
    // A single un-terminated byte (high bit set) — decode_next never breaks.
    expect((new PolylineDecoder())->firstPoint('_'))->toBeNull();
});

it('returns null when only the latitude chunk is present', function (): void {
    // Valid lat (`_p~iF` decodes cleanly), but nothing follows for lng.
    expect((new PolylineDecoder())->firstPoint('_p~iF'))->toBeNull();
});
