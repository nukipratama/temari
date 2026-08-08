import { describe, expect, it } from 'vitest';

import type { StreamSummaryPerKm } from '@/types/inertia';

import {
    FULL_SPREAD_SEC,
    barRowFill,
    computeBarWidth,
    paceScale,
    paceSecOf,
} from './splits';

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

describe('paceScale', () => {
    it('anchors on the fastest and slowest parseable pace', () => {
        expect(
            paceScale([
                row({ pace: '6:00' }),
                row({ pace: '5:30' }),
                row({ pace: '6:20' }),
            ]),
        ).toEqual({ fastest: 330, slowest: 380 });
    });

    it('ignores rows with no parseable pace', () => {
        expect(
            paceScale([row({ pace: 'n/a' }), row({ pace: '5:30' })]),
        ).toEqual({ fastest: 330, slowest: 330 });
    });

    it('reports no scale at all when nothing parses', () => {
        expect(paceScale([row({ pace: 'n/a' })])).toEqual({
            fastest: null,
            slowest: null,
        });
    });

    it('reports no scale for an empty row set', () => {
        expect(paceScale([])).toEqual({ fastest: null, slowest: null });
    });

    it('scales a lap row the same way as a split row', () => {
        expect(
            paceScale([{ pace: '6:00' }, { pace: '5:45' }, { pace: '6:10' }]),
        ).toEqual({ fastest: 345, slowest: 370 });
    });
});

describe('barRowFill', () => {
    it('tints the fastest row regardless of its position', () => {
        expect(barRowFill(true, 0)).toBe('bg-horizon/[0.08]');
        expect(barRowFill(true, 1)).toBe('bg-horizon/[0.08]');
    });

    it('zebra-stripes the other rows by position', () => {
        expect(barRowFill(false, 0)).toBe('bg-sky/[0.03]');
        expect(barRowFill(false, 1)).toBe('bg-cream-deep/30');
    });
});

const FASTEST_SEC = 300;
const FASTEST_WIDTH_PCT = 90;
const MAX_SWING_PCT = 50;
const NEUTRAL_WIDTH_PCT = 60;

describe('computeBarWidth', () => {
    it('returns the neutral width when the row pace is unknown', () => {
        expect(computeBarWidth(null, FASTEST_SEC, FASTEST_SEC + 30)).toBe(
            NEUTRAL_WIDTH_PCT,
        );
    });

    it('returns the neutral width when there is no fastest/slowest scale', () => {
        expect(computeBarWidth(FASTEST_SEC, null, FASTEST_SEC + 30)).toBe(
            NEUTRAL_WIDTH_PCT,
        );
        expect(computeBarWidth(FASTEST_SEC, FASTEST_SEC, null)).toBe(
            NEUTRAL_WIDTH_PCT,
        );
    });

    it('returns the neutral width when every split shares one pace (degenerate scale)', () => {
        expect(computeBarWidth(FASTEST_SEC, FASTEST_SEC, FASTEST_SEC)).toBe(
            NEUTRAL_WIDTH_PCT,
        );
    });

    it('anchors the fastest km at the fastest width', () => {
        expect(
            computeBarWidth(
                FASTEST_SEC,
                FASTEST_SEC,
                FASTEST_SEC + FULL_SPREAD_SEC,
            ),
        ).toBe(FASTEST_WIDTH_PCT);
    });

    it('drops the slowest km by the whole swing once the spread reaches FULL_SPREAD_SEC', () => {
        const slowest = FASTEST_SEC + FULL_SPREAD_SEC;
        expect(computeBarWidth(slowest, FASTEST_SEC, slowest)).toBe(
            FASTEST_WIDTH_PCT - MAX_SWING_PCT,
        );
    });

    it('drops a km halfway between the anchors by half the swing', () => {
        const slowest = FASTEST_SEC + FULL_SPREAD_SEC;
        const midpoint = FASTEST_SEC + FULL_SPREAD_SEC / 2;
        expect(computeBarWidth(midpoint, FASTEST_SEC, slowest)).toBe(
            FASTEST_WIDTH_PCT - MAX_SWING_PCT / 2,
        );
    });

    it('scales the swing down proportionally when the spread is under FULL_SPREAD_SEC', () => {
        const tightSpreadSec = 2;
        const slowest = FASTEST_SEC + tightSpreadSec;
        const compressedSwing = Math.round(
            (tightSpreadSec / FULL_SPREAD_SEC) * MAX_SWING_PCT,
        );
        expect(computeBarWidth(slowest, FASTEST_SEC, slowest)).toBe(
            FASTEST_WIDTH_PCT - compressedSwing,
        );
    });
});
