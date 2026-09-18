<?php

declare(strict_types=1);

use App\Services\Run\Story\Card\Svg;

it('wraps a body in a sized document, with defs only when there are any', function (): void {
    expect(Svg::doc(1080, 1920, '<g/>'))
        ->toContain('width="1080" height="1920" viewBox="0 0 1080 1920"')
        ->and(Svg::doc(1080, 1080, '<g/>'))->not->toContain('<defs>')
        ->and(Svg::doc(1080, 1080, '<g/>', '<filter id="f"/>'))->toContain('<defs><filter id="f"/></defs>');
});

it('omits an attribute it was given nothing for', function (): void {
    $plain = Svg::text('EASY RUN', 10, 20, 24, '#fff');

    expect($plain)->not->toContain('letter-spacing')
        ->not->toContain('text-anchor')
        ->not->toContain('font-style')
        ->and(Svg::text('X', 0, 0, 10, '#fff', anchor: 'end', tracking: 4, italic: true))
        ->toContain('text-anchor="end"')
        ->toContain('letter-spacing="4"')
        ->toContain('font-style="italic"');
});

it('escapes text so a run name can never break the document', function (): void {
    expect(Svg::text('5 & 6 <km>', 0, 0, 10, '#fff'))
        ->toContain('5 &amp; 6 &lt;km&gt;')
        ->not->toContain('<km>');
});

it('sets the wordmark in Fraunces italic, the one display use', function (): void {
    expect(Svg::wordmark(10, 20, 54, '#ade047'))
        ->toContain('font-family="Fraunces"')
        ->toContain('font-style="italic"')
        ->toContain('>temari<');
});

it('trims the trailing zeros off a coordinate', function (): void {
    expect(Svg::num(12.0))->toBe('12')
        ->and(Svg::num(12.5))->toBe('12.5')
        ->and(Svg::num(12.345))->toBe('12.35')
        ->and(Svg::num(-0.5))->toBe('-0.5');
});

it('draws a route as path data, closing it only when asked', function (): void {
    $points = [[0.0, 0.0], [10.0, 5.0], [20.0, 0.0]];

    expect(Svg::polylinePath($points))->toBe('M0,0L10,5L20,0')
        ->and(Svg::polylinePath($points, close: true))->toBe('M0,0L10,5L20,0Z')
        ->and(Svg::polylinePath([]))->toBe('');
});

it('measures mono text from its fixed advance', function (): void {
    // JetBrains Mono advances 0.6em per glyph, plus the tracking on each.
    expect(Svg::monoWidth('ABCDE', 20))->toBe(60.0)
        ->and(Svg::monoWidth('ABCDE', 20, 2))->toBe(70.0);
});

it('clips a label to a character budget without an ellipsis tail', function (): void {
    expect(Svg::clip('KANAL BANJIR BARAT', 10))->toBe('KANAL BANJ')
        ->and(Svg::clip('SENAYAN', 10))->toBe('SENAYAN');
});

it('drops a whole part rather than printing half its name', function (): void {
    expect(Svg::joinWithin(['SPEEDSTER', 'NEGATIVE SPLIT'], ' · ', 25))->toBe('SPEEDSTER')
        ->and(Svg::joinWithin(['SPEEDSTER', 'NEGATIVE SPLIT'], ' · ', 40))->toBe('SPEEDSTER · NEGATIVE SPLIT')
        ->and(Svg::joinWithin([], ' · ', 40))->toBe('');
});

it('calls a trace closed only when it ends where it started', function (): void {
    $box = [1000.0, 1000.0];

    expect(Svg::isClosedLoop([[0.0, 0.0], [500.0, 500.0], [5.0, 5.0]], ...$box))->toBeTrue()
        ->and(Svg::isClosedLoop([[0.0, 0.0], [500.0, 500.0], [900.0, 900.0]], ...$box))->toBeFalse()
        ->and(Svg::isClosedLoop([[0.0, 0.0]], ...$box))->toBeFalse();
});

it('picks the ink a fill can carry', function (): void {
    expect(Svg::readableInk('#f5a623'))->toBe(Svg::INK)      // legendary gold
        ->and(Svg::readableInk('#2f81f7'))->toBe(Svg::CREAM); // rare blue
});

it('draws a contour ring that closes and repeats for the same seed', function (): void {
    $ring = Svg::closedLoop(100, 100, 50, 40, 7);

    expect($ring)->toStartWith('M')
        ->toEndWith('Z')
        ->and(Svg::closedLoop(100, 100, 50, 40, 7))->toBe($ring)
        ->and(Svg::closedLoop(100, 100, 50, 40, 8))->not->toBe($ring);
});

it('spaces markers evenly along a trace, never on its endpoints', function (): void {
    $points = array_map(fn (int $i): array => [(float) $i, 0.0], range(0, 10));

    expect(Svg::along($points, 3))->toBe([[3.0, 0.0], [5.0, 0.0], [8.0, 0.0]])
        ->and(Svg::along($points, 0))->toBe([])
        ->and(Svg::along([], 3))->toBe([]);
});
