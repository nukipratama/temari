import { describe, expect, it } from 'vitest';

import { currentSeasonPhase } from './seasonPhase';

function week(
    phase: string,
    type: 'history' | 'current' | 'lookahead' = 'current',
) {
    return { phase, type };
}

describe('currentSeasonPhase', () => {
    it('returns the current week’s phase', () => {
        expect(
            currentSeasonPhase([
                week('build', 'history'),
                week('peak', 'current'),
                week('taper', 'lookahead'),
            ]),
        ).toBe('peak');
    });

    it('falls back to base when there is no current week', () => {
        expect(
            currentSeasonPhase([
                week('build', 'history'),
                week('taper', 'lookahead'),
            ]),
        ).toBe('base');
    });

    it('falls back to base for an empty week list', () => {
        expect(currentSeasonPhase([])).toBe('base');
    });

    it('borrows the last non-deload phase on a deload week instead of resetting', () => {
        expect(
            currentSeasonPhase([
                week('build', 'history'),
                week('deload', 'current'),
            ]),
        ).toBe('build');
    });

    it('falls back to base when every prior week is also deload', () => {
        expect(
            currentSeasonPhase([
                week('deload', 'history'),
                week('deload', 'current'),
            ]),
        ).toBe('base');
    });
});
