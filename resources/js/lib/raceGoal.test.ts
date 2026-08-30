import { describe, expect, it } from 'vitest';

import {
    ambitiousGoalWarning,
    earliestRaceDate,
    goalTimeError,
    impossiblePaceWarning,
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

describe('impossiblePaceWarning', () => {
    it('warns when the pace beats the world-record floor', () => {
        // 10K in 25:00 = 150 sec/km, under the 155 sec/km floor.
        expect(impossiblePaceWarning(10, 1_500)).toBe(
            "That's 2:30/km, quicker than world-record pace for most distances. Worth double-checking, but you can still save it.",
        );
    });

    it('stays quiet for a plausible pace', () => {
        // 10K in 50:00 = 300 sec/km.
        expect(impossiblePaceWarning(10, 3_000)).toBeNull();
    });

    it('stays quiet when distance or time is not yet set', () => {
        expect(impossiblePaceWarning(0, 3_000)).toBeNull();
        expect(impossiblePaceWarning(10, 0)).toBeNull();
    });
});

describe('ambitiousGoalWarning', () => {
    const projection = { distanceKm: 10, lowSec: 3_000, highSec: 3_300 };

    it("warns when the goal is well ahead of the athlete's own projected range", () => {
        // 3,000 * 0.9 = 2,700 - anything under that is a real stretch.
        expect(ambitiousGoalWarning(10, 2_600, projection)).toBe(
            "That's well ahead of your own projected range (50:00–55:00). Ambitious, but you can still save it.",
        );
    });

    it('stays quiet for a goal inside the stretch ratio', () => {
        expect(ambitiousGoalWarning(10, 2_900, projection)).toBeNull();
    });

    it('stays quiet when there is no projection to compare against', () => {
        expect(ambitiousGoalWarning(10, 2_600, null)).toBeNull();
    });

    it('stays quiet when the form distance no longer matches the projection', () => {
        expect(ambitiousGoalWarning(21.1, 2_600, projection)).toBeNull();
    });
});
