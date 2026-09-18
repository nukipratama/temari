import { describe, expect, it } from 'vitest';

import {
    along,
    circle,
    closedLoop,
    doc,
    escape,
    filledCells,
    fitSize,
    group,
    isClosedLoop,
    joinWithin,
    line,
    measure,
    num,
    path,
    polylinePath,
    rect,
    serialSeed,
    text,
    truncate,
    wordmark,
    type Point,
} from '@/lib/card/svg';

describe('svg primitives', () => {
    it('draws nothing for a label with nothing to say', () => {
        expect(text('', 0, 0, 20, '#000')).toBe('');
        expect(text('   ', 0, 0, 20, '#000')).toBe('');
        expect(text('5.28', 0, 0, 20, '#000')).toContain('>5.28<');
    });

    it('escapes what it sets, so a run name cannot break the document', () => {
        expect(escape('a & <b> "c"')).toBe('a &amp; &lt;b&gt; &quot;c&quot;');
        expect(text('a & b', 0, 0, 20, '#000')).toContain('a &amp; b');
    });

    it('omits an attribute it was given nothing for', () => {
        const plain = rect(0, 0, 10, 10);
        expect(plain).not.toContain('stroke=');
        expect(plain).toContain('fill="none"');
        expect(
            rect(0, 0, 10, 10, { stroke: '#000', strokeWidth: 2, radius: 4 }),
        ).toContain('rx="4"');
    });

    it('rounds coordinates down to two decimals to keep the file small', () => {
        expect(num(12.3456)).toBe('12.35');
        expect(num(12)).toBe('12');
        expect(num(12.5)).toBe('12.5');
    });

    it('wraps a document at the exact pixel size, with defs only when given', () => {
        expect(doc(1080, 1920, '<g/>')).toContain(
            'width="1080" height="1920" viewBox="0 0 1080 1920"',
        );
        expect(doc(1, 1, '', '<filter/>')).toContain('<defs><filter/></defs>');
        expect(doc(1, 1, '')).not.toContain('<defs>');
    });

    it('sets the wordmark in the one italic display face', () => {
        expect(wordmark(0, 0, 40, '#000')).toContain('font-style="italic"');
        expect(wordmark(0, 0, 40, '#000')).toContain('Fraunces');
    });

    it('emits the other primitives with their own geometry', () => {
        expect(line(0, 0, 10, 0, '#000', { dash: '2 2' })).toContain(
            'stroke-dasharray="2 2"',
        );
        expect(circle(1, 2, 3, { fill: '#fff' })).toContain('r="3"');
        expect(path('M0,0L1,1', { stroke: '#000' })).toContain('d="M0,0L1,1"');
        expect(group('<g/>', { clip: 'url(#x)' })).toContain('clip-path');
        expect(group('<g/>')).toBe('<g><g/></g>');
    });
});

describe('measurement', () => {
    it('scales linearly with the size and adds the tracking per glyph', () => {
        const single = measure('ABCD', 100);
        expect(measure('ABCD', 200)).toBeCloseTo(single * 2, 5);
        expect(measure('ABCD', 100, { tracking: 5 })).toBeCloseTo(
            single + 20,
            5,
        );
    });

    it('fits a figure to the room it has instead of stepping a fixed ladder', () => {
        const wide = fitSize('1:52:30', 400, 200);
        const narrow = fitSize('18.40', 400, 200);

        expect(wide).toBeLessThan(narrow);
        expect(measure('1:52:30', wide)).toBeCloseTo(200, 5);
        // Never grows past the nominal size just because there is room.
        expect(fitSize('5', 120, 5000)).toBe(120);
        expect(fitSize('', 120, 500)).toBe(120);
    });

    it('clips a label to a width budget rather than a character count', () => {
        const long = 'JAKARTA SELATAN, DAERAH KHUSUS IBUKOTA';
        const clipped = truncate(long, 20, 200);

        expect(clipped.length).toBeLessThan(long.length);
        expect(measure(clipped, 20)).toBeLessThanOrEqual(200);
        expect(truncate('SHORT', 20, 500)).toBe('SHORT');
        expect(truncate('SHORT', 900, 1)).toBe('');
    });

    it('joins as many parts as fit and never splits one', () => {
        const parts = ['HEAT TAMER', 'SPEEDSTER', 'NIGHT OWL'];
        const joined = joinWithin(parts, '  ·  ', 20, 260);

        expect(joined).toBe('HEAT TAMER');
        expect(joinWithin(parts, '  ·  ', 20, 10)).toBe('');
        expect(joinWithin(parts, '  ·  ', 20, 5000)).toContain('NIGHT OWL');
    });
});

describe('geometry', () => {
    const square: Point[] = [
        [0, 0],
        [10, 0],
        [10, 10],
        [0, 10],
        [0, 0],
    ];

    it('builds a path out of projected points, open or closed', () => {
        expect(
            polylinePath([
                [0, 0],
                [1, 2],
            ]),
        ).toBe('M0,0L1,2');
        expect(
            polylinePath(
                [
                    [0, 0],
                    [1, 2],
                ],
                true,
            ),
        ).toBe('M0,0L1,2Z');
        expect(polylinePath([])).toBe('');
    });

    it('reads a trace that ends where it started as a loop', () => {
        expect(isClosedLoop(square, 10, 10)).toBe(true);
        expect(isClosedLoop(square.slice(0, 3), 10, 10)).toBe(false);
        expect(isClosedLoop([[0, 0]], 10, 10)).toBe(false);
    });

    it('picks evenly along a trace for km ticks', () => {
        expect(along(square, 2)).toHaveLength(2);
        expect(along(square, 0)).toEqual([]);
        expect(along([], 3)).toEqual([]);
    });

    it('draws the same landscape for the same seed', () => {
        expect(closedLoop(0, 0, 10, 10, 7)).toBe(closedLoop(0, 0, 10, 10, 7));
        expect(closedLoop(0, 0, 10, 10, 7)).not.toBe(
            closedLoop(0, 0, 10, 10, 8),
        );
    });

    it('seeds a plate off its serial, the same way the server did', () => {
        expect(serialSeed('TMR-0418')).toBe(serialSeed('TMR-0418'));
        expect(serialSeed('TMR-0418')).not.toBe(serialSeed('TMR-0419'));
        expect(serialSeed('TMR-0418')).toBeLessThan(997);
    });

    it('drops a cell it has no value for, so a row never divides by it', () => {
        expect(
            filledCells([
                ['TIME', '32:18'],
                ['AVG HR', ''],
            ]),
        ).toEqual([['TIME', '32:18']]);
    });
});
