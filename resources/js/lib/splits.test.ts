import { describe, expect, it } from 'vitest';
import { FULL_SPREAD_SEC, computeBarWidth, paceSecOf } from './splits';
import type { StreamSummaryPerKm } from '@/types/inertia';

function row(overrides: Partial<StreamSummaryPerKm> = {}): StreamSummaryPerKm {
    return { km: 1, pace: '5:30', ...overrides };
}

describe('paceSecOf', () => {
    it('parses an "M:SS" pace into seconds', () => {
        expect(paceSecOf(row({ pace: '5:30' }))).toBe(330);
    });

    it('returns null for a pace with no "M:SS" shape', () => {
        expect(paceSecOf(row({ pace: 'n/a' }))).toBeNull();
    });

    it('returns null when a "M:SS" segment is not numeric', () => {
        expect(paceSecOf(row({ pace: 'x:30' }))).toBeNull();
    });
});

const FASTEST_SEC = 300;
const FASTEST_WIDTH_PCT = 90;
const MAX_SWING_PCT = 50;
const NEUTRAL_WIDTH_PCT = 60;

describe('computeBarWidth', () => {
    it('returns the neutral width when the row pace is unknown', () => {
        expect(computeBarWidth(null, FASTEST_SEC, FASTEST_SEC + 30)).toBe(NEUTRAL_WIDTH_PCT);
    });

    it('returns the neutral width when there is no fastest/slowest scale', () => {
        expect(computeBarWidth(FASTEST_SEC, null, FASTEST_SEC + 30)).toBe(NEUTRAL_WIDTH_PCT);
        expect(computeBarWidth(FASTEST_SEC, FASTEST_SEC, null)).toBe(NEUTRAL_WIDTH_PCT);
    });

    it('returns the neutral width when every split shares one pace (degenerate scale)', () => {
        expect(computeBarWidth(FASTEST_SEC, FASTEST_SEC, FASTEST_SEC)).toBe(NEUTRAL_WIDTH_PCT);
    });

    it('anchors the fastest km at the fastest width', () => {
        expect(computeBarWidth(FASTEST_SEC, FASTEST_SEC, FASTEST_SEC + FULL_SPREAD_SEC)).toBe(FASTEST_WIDTH_PCT);
    });

    it('drops the slowest km by the whole swing once the spread reaches FULL_SPREAD_SEC', () => {
        const slowest = FASTEST_SEC + FULL_SPREAD_SEC;
        expect(computeBarWidth(slowest, FASTEST_SEC, slowest)).toBe(FASTEST_WIDTH_PCT - MAX_SWING_PCT);
    });

    it('drops a km halfway between the anchors by half the swing', () => {
        const slowest = FASTEST_SEC + FULL_SPREAD_SEC;
        const midpoint = FASTEST_SEC + FULL_SPREAD_SEC / 2;
        expect(computeBarWidth(midpoint, FASTEST_SEC, slowest)).toBe(FASTEST_WIDTH_PCT - MAX_SWING_PCT / 2);
    });

    it('scales the swing down proportionally when the spread is under FULL_SPREAD_SEC', () => {
        const tightSpreadSec = 2;
        const slowest = FASTEST_SEC + tightSpreadSec;
        const compressedSwing = Math.round((tightSpreadSec / FULL_SPREAD_SEC) * MAX_SWING_PCT);
        expect(computeBarWidth(slowest, FASTEST_SEC, slowest)).toBe(FASTEST_WIDTH_PCT - compressedSwing);
    });
});
