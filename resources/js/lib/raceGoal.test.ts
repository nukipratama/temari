import { describe, expect, it } from 'vitest';

import {
    earliestRaceDate,
    goalTimeError,
    MAX_GOAL_TIME_SEC,
    MIN_GOAL_TIME_SEC,
} from './raceGoal';

describe('earliestRaceDate', () => {
    it('is the local calendar day after the given date', () => {
        expect(earliestRaceDate(new Date(2026, 7, 13, 9, 30))).toBe(
            '2026-08-14',
        );
    });

    it('rolls over the month and the year', () => {
        expect(earliestRaceDate(new Date(2026, 7, 31, 9, 30))).toBe(
            '2026-09-01',
        );
        expect(earliestRaceDate(new Date(2026, 11, 31, 23, 59))).toBe(
            '2027-01-01',
        );
    });

    it('reads the local calendar day, not the UTC one', () => {
        // 23:30 local on the 13th is already the 14th in UTC for Asia/Jakarta.
        // Going through toISOString() here would return 2026-08-15.
        expect(earliestRaceDate(new Date(2026, 7, 13, 23, 30))).toBe(
            '2026-08-14',
        );
    });
});

describe('goalTimeError', () => {
    it('accepts a time inside the server bounds', () => {
        expect(goalTimeError(MIN_GOAL_TIME_SEC)).toBeNull();
        expect(goalTimeError(3_000)).toBeNull();
        expect(goalTimeError(MAX_GOAL_TIME_SEC)).toBeNull();
    });

    it('rejects a time the server would reject as too short', () => {
        expect(goalTimeError(0)).toBe(
            'Goal time has to be at least 5 minutes.',
        );
        expect(goalTimeError(MIN_GOAL_TIME_SEC - 1)).toBe(
            'Goal time has to be at least 5 minutes.',
        );
    });

    it('rejects a time the server would reject as too long', () => {
        expect(goalTimeError(MAX_GOAL_TIME_SEC + 1)).toBe(
            'Goal time has to be under 72 hours.',
        );
    });
});
