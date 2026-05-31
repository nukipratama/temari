import { describe, expect, it } from 'vitest';
import { projectPolyline } from './route';

const SAMPLE = '_p~iF~ps|U_ulLnnqC_mqNvxq`@';
const SINGLE_POINT = '_p~iF~ps|U';

describe('projectPolyline', () => {
    it('returns null for missing / empty / single-point input', () => {
        expect(projectPolyline(null, 100, 64, 8)).toBeNull();
        expect(projectPolyline('', 100, 64, 8)).toBeNull();
        // A lone coordinate has nothing to draw between, so it is not a route.
        expect(projectPolyline(SINGLE_POINT, 100, 64, 8)).toBeNull();
    });

    it('projects a polyline into the padded box bounds', () => {
        const out = projectPolyline(SAMPLE, 100, 64, 8);
        if (out === null) {
            throw new Error('expected a projected route');
        }
        expect(out.points.length).toBeGreaterThanOrEqual(2);
        expect(out.start).toEqual(out.points[0]);
        for (const [x, y] of out.points) {
            expect(x).toBeGreaterThanOrEqual(8 - 0.01);
            expect(x).toBeLessThanOrEqual(92 + 0.01);
            expect(y).toBeGreaterThanOrEqual(8 - 0.01);
            expect(y).toBeLessThanOrEqual(56 + 0.01);
        }
    });

    it('downsamples to maxPoints (keeping the last point)', () => {
        const out = projectPolyline(SAMPLE, 100, 64, 8, 2);
        if (out === null) {
            throw new Error('expected a projected route');
        }
        expect(out.points.length).toBeLessThanOrEqual(3); // <= maxPoints + the appended last
    });
});
