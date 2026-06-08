import { describe, expect, it } from 'vitest';
import {
    streakLabel,
    weekRangeLabel,
    weeklyDeltaDirection,
    weeklyDeltaLabel,
} from './weeklyRecap';

describe('weeklyDeltaLabel', () => {
    it('nudges first-week users when there is no comparable baseline', () => {
        expect(weeklyDeltaLabel(null)).toBe('minggu pertama kamu');
    });

    it('reads a 0% delta as "sama" rather than "+0%"', () => {
        expect(weeklyDeltaLabel(0)).toBe('sama kayak minggu lalu');
    });

    it('prefixes a positive delta with +', () => {
        expect(weeklyDeltaLabel(12)).toBe('+12% dari minggu lalu');
    });

    it('prefixes a negative delta with - and drops the double minus', () => {
        expect(weeklyDeltaLabel(-25)).toBe('-25% dari minggu lalu');
    });
});

describe('weeklyDeltaDirection', () => {
    it('is flat for null', () => {
        expect(weeklyDeltaDirection(null)).toBe('flat');
    });

    it('is flat for zero', () => {
        expect(weeklyDeltaDirection(0)).toBe('flat');
    });

    it('is up for a positive delta', () => {
        expect(weeklyDeltaDirection(5)).toBe('up');
    });

    it('is down for a negative delta', () => {
        expect(weeklyDeltaDirection(-5)).toBe('down');
    });
});

describe('weekRangeLabel', () => {
    it('formats an ISO week range as "day month - day month"', () => {
        expect(weekRangeLabel('2026-05-11', '2026-05-17')).toBe('11 Mei - 17 Mei');
    });

    it('returns an empty string on malformed input', () => {
        expect(weekRangeLabel('not-a-date', '2026-05-17')).toBe('');
    });
});

describe('streakLabel', () => {
    it('returns null below 2 weeks (nothing to brag yet)', () => {
        expect(streakLabel(0)).toBeNull();
        expect(streakLabel(1)).toBeNull();
    });

    it('labels a 3-week streak', () => {
        expect(streakLabel(3)).toBe('3 minggu beruntun');
    });
});
