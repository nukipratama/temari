import { afterEach, describe, expect, it, vi } from 'vitest';

import type { RaceAmbition, RaceSupport } from '@/types/inertia';

import {
    ambitionNote,
    ambitiousGoalWarning,
    earliestRaceDate,
    goalGap,
    goalGapPose,
    goalTimeError,
    impossiblePaceWarning,
    MAX_GOAL_TIME_SEC,
    MIN_GOAL_TIME_SEC,
    ON_GOAL_TOLERANCE_SEC,
    raceDistanceLabel,
    supportedBasisLine,
    supportedEyebrow,
} from './raceGoal';

describe('earliestRaceDate', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('is the calendar day after the given server date', () => {
        expect(earliestRaceDate('2026-08-13')).toBe('2026-08-14');
    });

    it('rolls over the month and the year', () => {
        expect(earliestRaceDate('2026-08-31')).toBe('2026-09-01');
        expect(earliestRaceDate('2026-12-31')).toBe('2027-01-01');
    });

    it('follows the server date when the device clock is already a day ahead', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 7, 14, 0, 30));

        expect(earliestRaceDate('2026-08-13')).toBe('2026-08-14');
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
            'goal time has to be at least 5 minutes.',
        );
        expect(goalTimeError(MIN_GOAL_TIME_SEC - 1)).toBe(
            'goal time has to be at least 5 minutes.',
        );
    });

    it('rejects a time the server would reject as too long', () => {
        expect(goalTimeError(MAX_GOAL_TIME_SEC + 1)).toBe(
            'goal time has to be under 72 hours.',
        );
    });
});

describe('impossiblePaceWarning', () => {
    it('warns when the pace beats the world-record floor', () => {
        // 10K in 25:00 = 150 sec/km, under the 155 sec/km floor.
        expect(impossiblePaceWarning(10, 1_500)).toBe(
            "that's 2:30/km, quicker than world-record pace for most distances. worth double-checking, but you can still save it.",
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
            "that's well ahead of your own projected range (50:00–55:00). ambitious, but you can still save it.",
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

describe('goalGap', () => {
    it('names a slower projection as the time behind', () => {
        expect(goalGap(3_000, 3_509)).toEqual({
            verdict: 'behind',
            label: '8:29 behind',
        });
    });

    it('names a faster projection as the time ahead', () => {
        expect(goalGap(3_000, 2_870)).toEqual({
            verdict: 'ahead',
            label: '2:10 ahead',
        });
    });

    it('calls it on goal within the tolerance either way', () => {
        expect(goalGap(3_000, 3_000 + ON_GOAL_TOLERANCE_SEC).label).toBe(
            'on goal',
        );
        expect(goalGap(3_000, 3_000 - ON_GOAL_TOLERANCE_SEC).verdict).toBe(
            'on',
        );
        expect(goalGap(3_000, 3_000 + ON_GOAL_TOLERANCE_SEC + 1).label).toBe(
            '0:06 behind',
        );
    });

    it('rounds a fractional projection before measuring the gap', () => {
        expect(goalGap(3_000, 3_105.6).label).toBe('1:46 behind');
    });

    it('carries hours on a gap past the hour', () => {
        expect(goalGap(10_000, 13_723).label).toBe('1:02:03 behind');
    });
});

describe('goalGapPose', () => {
    it.each([
        [9_000, 'blazing'],
        [10_000, 'blazing'],
        [10_100, 'blazing'],
        [10_101, 'easy'],
        [10_300, 'easy'],
        [10_301, 'wobbly'],
        [10_800, 'wobbly'],
        [10_801, 'gassed'],
    ] as const)(
        'poses a %i projection against a 10000 goal as %s',
        (predicted, pose) => {
            expect(goalGapPose(10_000, predicted)).toBe(pose);
        },
    );
});

describe('ambitionNote', () => {
    const ambition: RaceAmbition = {
        state: 'on_track',
        target_time_sec: 3_000,
        target_pace_sec_per_km: 300,
        supported_time_sec: 3_050,
        supported_pace_sec_per_km: 305,
        prescribed_time_sec: 3_000,
        gap_pct: 1.6,
        evidence_confidence: 'confirmed',
        basis: null,
        confirm_nudge: false,
    };
    const support: RaceSupport = {
        mode: 'road',
        dedicated_preparation: true,
        limitation: null,
    };

    it.each([
        ['on_track', /^on track: your target is within 3%/],
        [
            'ambitious',
            /^ambitious: your target is 1\.6% faster.*trains at your target/,
        ],
        ['unsupported', /^unsupported: .*trains at the supported effort/],
        ['low_evidence', /^low evidence: .*less than half this distance/],
    ] as const)('words the %s state', (state, expected) => {
        expect(ambitionNote({ ...ambition, state }, support)).toMatch(expected);
    });

    it('falls back to the honest limit, then to too few results', () => {
        const unknown = {
            ...ambition,
            state: 'unknown' as const,
            supported_time_sec: null,
        };

        expect(
            ambitionNote(unknown, {
                ...support,
                limitation: 'general aerobic only.',
            }),
        ).toBe('general aerobic only.');
        expect(ambitionNote(unknown, support)).toBe(
            'not enough recent results to compare yet.',
        );
    });
});

describe('supportedEyebrow', () => {
    const base: RaceAmbition = {
        state: 'on_track',
        target_time_sec: 3_000,
        target_pace_sec_per_km: 300,
        supported_time_sec: 2_990,
        supported_pace_sec_per_km: 299,
        prescribed_time_sec: 3_000,
        gap_pct: -0.3,
        evidence_confidence: 'confirmed',
        basis: null,
        confirm_nudge: false,
    };

    it('reads on track for only in the band with the supported time not behind', () => {
        expect(supportedEyebrow(base)).toBe('on track for');
        expect(supportedEyebrow({ ...base, supported_time_sec: 3_004 })).toBe(
            'on track for',
        );
        expect(supportedEyebrow({ ...base, supported_time_sec: 3_060 })).toBe(
            'supported',
        );
        expect(supportedEyebrow({ ...base, state: 'ambitious' })).toBe(
            'supported',
        );
        expect(supportedEyebrow({ ...base, state: 'low_evidence' })).toBe(
            'supported',
        );
    });
});

describe('raceDistanceLabel', () => {
    it.each([
        [5_000, '5K'],
        [10_000, '10K'],
        [15_000, '15K'],
        [21_098, 'half marathon'],
        [42_195, 'marathon'],
        [7_300, '7.3 km'],
    ])('names %d m as %s', (meters, label) => {
        expect(raceDistanceLabel(meters)).toBe(label);
    });
});

describe('supportedBasisLine', () => {
    const ambition: RaceAmbition = {
        state: 'on_track',
        target_time_sec: 3_480,
        target_pace_sec_per_km: 348,
        supported_time_sec: 3_570,
        supported_pace_sec_per_km: 357,
        prescribed_time_sec: 3_480,
        gap_pct: 2.5,
        evidence_confidence: 'provisional',
        basis: {
            distance_m: 5_000,
            performed_on: '2026-08-26',
            activity_id: 7,
        },
        confirm_nudge: true,
    };

    it('names the effort the supported time rests on', () => {
        expect(supportedBasisLine(ambition)).toBe('based on your 5K on aug 26');
    });

    it('says nothing without a supported time or a basis', () => {
        expect(
            supportedBasisLine({ ...ambition, supported_time_sec: null }),
        ).toBeNull();
        expect(supportedBasisLine({ ...ambition, basis: null })).toBeNull();
    });
});
