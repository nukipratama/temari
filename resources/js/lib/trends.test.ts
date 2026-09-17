import { describe, expect, it } from 'vitest';

import { ctlDaysAgo, ctlNow, ctlPeak, type CtlPoint } from './trends';

function series(values: number[]): CtlPoint[] {
    return values.map((ctl, i) => ({
        date: `2026-01-${String(i + 1).padStart(2, '0')}`,
        atl: ctl,
        ctl,
    }));
}

describe('ctlNow', () => {
    it('reads the last point', () => {
        expect(ctlNow(series([10, 20, 30]))).toBe(30);
    });

    it('is null for an empty series', () => {
        expect(ctlNow([])).toBeNull();
    });
});

describe('ctlDaysAgo', () => {
    it('reads the point N days before the last one', () => {
        expect(ctlDaysAgo(series([10, 20, 30, 40]), 1)).toBe(30);
        expect(ctlDaysAgo(series([10, 20, 30, 40]), 3)).toBe(10);
    });

    it('is null when the series does not reach back that far', () => {
        expect(ctlDaysAgo(series([10, 20]), 30)).toBeNull();
    });
});

describe('ctlPeak', () => {
    it('reads the highest value in the series', () => {
        expect(ctlPeak(series([10, 45, 20, 30]))).toBe(45);
    });

    it('is null for an empty series', () => {
        expect(ctlPeak([])).toBeNull();
    });
});
