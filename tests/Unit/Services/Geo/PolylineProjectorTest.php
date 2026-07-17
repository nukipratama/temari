<?php

declare(strict_types=1);

use App\Services\Geo\PolylineDecoder;
use App\Services\Geo\PolylineProjector;

function project(?string $polyline, float $w = 320, float $h = 320, float $pad = 24): ?string
{
    return new PolylineProjector(new PolylineDecoder())->project($polyline, $w, $h, $pad);
}

/**
 * @return list<array{0: float, 1: float}>
 */
function parseProjectedCoords(string $points): array
{
    return array_map(
        fn (string $pair): array => array_map(floatval(...), explode(',', $pair)),
        explode(' ', $points),
    );
}

it('returns null when there is no polyline', function (): void {
    expect(project(null))->toBeNull()
        ->and(project(''))->toBeNull();
});

it('returns null when the polyline decodes to fewer than two points', function (): void {
    // A single [lat,lng] pair encodes to one point — not drawable.
    expect(project('_p~iF~ps|U'))->toBeNull();
});

it('projects points fitted inside the padded box', function (): void {
    $points = project('_p~iF~ps|U_ulLnnqC_mqNvxq`@', 320, 320, 24);

    expect($points)->not->toBeNull();

    $coords = array_map(
        fn (string $pair): array => array_map(floatval(...), explode(',', $pair)),
        explode(' ', (string) $points),
    );

    foreach ($coords as [$x, $y]) {
        expect($x)->toBeGreaterThanOrEqual(24.0)->toBeLessThanOrEqual(296.0)
            ->and($y)->toBeGreaterThanOrEqual(24.0)->toBeLessThanOrEqual(296.0);
    }
});

it('honours a non-square box', function (): void {
    $points = project('_p~iF~ps|U_ulLnnqC_mqNvxq`@', 484, 330, 34);

    $xs = array_map(fn (string $pair): float => (float) explode(',', $pair)[0], explode(' ', (string) $points));

    expect(max($xs))->toBeLessThanOrEqual(450.0); // width(484) - pad(34)
});

it('handles a perfectly horizontal route (constant latitude) without dividing by zero', function (): void {
    // Real degenerate polylines are fiddly to hand-encode, so the decoder is
    // faked here to inject exact points — the point of this test is the
    // spanLat=0 guard in PolylineProjector itself, not decoding.
    $decoder = Mockery::mock(PolylineDecoder::class);
    $decoder->shouldReceive('decode')->andReturn([[1.0, 2.0], [1.0, 3.0]]);

    $points = new PolylineProjector($decoder)->project('irrelevant', 320, 320, 24);

    expect($points)->not->toBeNull();
    $coords = parseProjectedCoords($points);
    // Constant latitude -> constant y; longitude differs -> x differs. Both finite.
    expect($coords[0][1])->toBe($coords[1][1])
        ->and($coords[0][0])->not->toBe($coords[1][0]);
    foreach ($coords as [$x, $y]) {
        expect(is_finite($x))->toBeTrue()->and(is_finite($y))->toBeTrue();
    }
});

it('handles a perfectly vertical route (constant longitude) without dividing by zero', function (): void {
    $decoder = Mockery::mock(PolylineDecoder::class);
    $decoder->shouldReceive('decode')->andReturn([[1.0, 2.0], [2.0, 2.0]]);

    $points = new PolylineProjector($decoder)->project('irrelevant', 320, 320, 24);

    expect($points)->not->toBeNull();
    $coords = parseProjectedCoords($points);
    // Constant longitude -> constant x; latitude differs -> y differs. Both finite.
    expect($coords[0][0])->toBe($coords[1][0])
        ->and($coords[0][1])->not->toBe($coords[1][1]);
    foreach ($coords as [$x, $y]) {
        expect(is_finite($x))->toBeTrue()->and(is_finite($y))->toBeTrue();
    }
});
