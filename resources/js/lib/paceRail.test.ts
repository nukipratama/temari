import { describe, expect, it } from 'vitest';

import { layoutPaceLabels, type PaceRailLabel } from './paceRail';

function label(position: number, below = false, width = 20): PaceRailLabel {
    return { position, below, width };
}

describe('layoutPaceLabels', () => {
    it('centres every label on its own dot when nothing collides', () => {
        const placed = layoutPaceLabels([label(45, true), label(70)]);

        expect(placed.map((item) => item.left)).toEqual([35, 60]);
    });

    it('holds the end labels inside the rail rather than centring them', () => {
        const placed = layoutPaceLabels([label(0), label(100)]);

        expect(placed.map((item) => item.left)).toEqual([0, 80]);
    });

    it('pushes two crowded same-side labels apart, leaving the dots alone', () => {
        const placed = layoutPaceLabels([label(92, true), label(100, true)]);

        expect(placed.map((item) => item.position)).toEqual([92, 100]);
        expect(placed.map((item) => item.left)).toEqual([60, 80]);
    });

    it('leaves neighbours on the other side of the rail out of it', () => {
        const placed = layoutPaceLabels([label(92, true), label(95)]);

        expect(placed.map((item) => item.left)).toEqual([80, 80]);
    });

    it('separates three clustered labels', () => {
        const placed = layoutPaceLabels([
            label(40, false, 15),
            label(44, false, 15),
            label(46, false, 15),
        ]);

        expect(placed.map((item) => item.left)).toEqual([32.5, 47.5, 62.5]);
    });

    it('keeps a cluster at the rail end inside both bounds', () => {
        const placed = layoutPaceLabels([
            label(80, false, 25),
            label(95, false, 25),
            label(100, false, 25),
        ]);

        expect(placed.map((item) => item.left)).toEqual([25, 50, 75]);
        for (const item of placed) {
            expect(item.left).toBeGreaterThanOrEqual(0);
            expect(item.left + item.width).toBeLessThanOrEqual(100);
        }
    });

    it('spreads labels that share a single position', () => {
        const placed = layoutPaceLabels([label(50), label(50)]);

        expect(placed.map((item) => item.left)).toEqual([40, 60]);
    });
});
