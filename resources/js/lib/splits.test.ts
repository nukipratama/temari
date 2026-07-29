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

describe('computeBarWidth', () => {
    it('returns the neutral 60 when the row pace is unknown', () => {
        expect(computeBarWidth(null, 300, 330)).toBe(60);
    });

    it('returns the neutral 60 when there is no fastest/slowest scale', () => {
        expect(computeBarWidth(300, null, 330)).toBe(60);
        expect(computeBarWidth(300, 300, null)).toBe(60);
    });

    it('returns the neutral 60 when every split shares one pace (degenerate scale)', () => {
        expect(computeBarWidth(300, 300, 300)).toBe(60);
    });

    it('anchors the fastest km at 90%', () => {
        expect(computeBarWidth(300, 300, 400)).toBe(90);
    });

    it('drops the slowest km by the full 50-point swing once the spread reaches FULL_SPREAD_SEC', () => {
        expect(computeBarWidth(300 + FULL_SPREAD_SEC, 300, 300 + FULL_SPREAD_SEC)).toBe(40);
    });

    it('compresses the band on a tight spread so near-equal kms read as near-equal bars', () => {
        // 2s spread → amplitude 2/30*50 ≈ 3.3, so the slowest km still sits at ~87%.
        expect(computeBarWidth(302, 300, 302)).toBe(87);
    });

    it('places a mid-pack km between the two anchors', () => {
        // Halfway across a full-amplitude 30s spread: 90 - 0.5 * 50.
        expect(computeBarWidth(315, 300, 330)).toBe(65);
    });
});
