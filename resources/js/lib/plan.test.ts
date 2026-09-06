import { describe, expect, it } from 'vitest';

import type { SeasonSummaryWeek } from './plan';

import {
    clampSummary,
    computeAdherence,
    phasesOf,
    weekdayLabel,
    weekRangeLabel,
} from './plan';

function week(overrides: Partial<SeasonSummaryWeek> = {}): SeasonSummaryWeek {
    return {
        week_start: '2026-06-15',
        phase: 'base',
        type: 'history',
        planned_km: 30,
        actual_km: null,
        sessions: 5,
        ...overrides,
    };
}

const RACE_SEASON: SeasonSummaryWeek[] = [
    week({ week_start: '2026-06-15', phase: 'base', planned_km: 30 }),
    week({
        week_start: '2026-06-22',
        phase: 'build',
        planned_km: 40,
        type: 'current',
    }),
    week({
        week_start: '2026-06-29',
        phase: 'peak',
        planned_km: 50,
        type: 'lookahead',
    }),
];

describe('computeAdherence', () => {
    it('returns null when nothing has been scored', () => {
        expect(
            computeAdherence([
                { compliance_score: null },
                { compliance_score: null },
            ]),
        ).toBeNull();
    });

    it('averages only the scored days, ignoring unscored ones', () => {
        expect(
            computeAdherence([
                { compliance_score: 80 },
                { compliance_score: 60 },
                { compliance_score: null },
            ]),
        ).toBe(70);
    });

    it('caps at 100 so one big overreach cannot read as a 140% season', () => {
        expect(
            computeAdherence([
                { compliance_score: 100 },
                { compliance_score: 220 },
            ]),
        ).toBe(100);
    });

    it('rounds to a whole percentage', () => {
        expect(
            computeAdherence([
                { compliance_score: 80 },
                { compliance_score: 81 },
                { compliance_score: 83 },
            ]),
        ).toBe(81);
    });
});

describe('weekRangeLabel', () => {
    it('names the month once when the week does not cross one', () => {
        expect(weekRangeLabel('2026-06-15')).toBe('jun 15–21');
    });

    it('names both months when the week crosses one', () => {
        expect(weekRangeLabel('2026-06-29')).toBe('jun 29–jul 5');
    });

    it('snaps a mid-week date back to its Monday', () => {
        expect(weekRangeLabel('2026-06-18')).toBe(weekRangeLabel('2026-06-15'));
    });
});

describe('weekdayLabel', () => {
    it('names the weekday a date falls on', () => {
        expect(weekdayLabel('2026-06-15')).toBe('Mon');
        expect(weekdayLabel('2026-06-21')).toBe('Sun');
    });

    it('returns an empty string for an unparseable date', () => {
        expect(weekdayLabel('not-a-date')).toBe('');
    });
});

describe('phasesOf', () => {
    it('averages each phase’s weekly volume', () => {
        const phases = phasesOf([
            week({ phase: 'base', planned_km: 30 }),
            week({ week_start: '2026-06-22', phase: 'base', planned_km: 40 }),
        ]);

        expect(phases).toEqual([{ key: 'base', avgKm: 35, state: 'done' }]);
    });

    it('marks the phase holding the current week as current', () => {
        expect(phasesOf(RACE_SEASON).map((p) => p.state)).toEqual([
            'done',
            'current',
            'upcoming',
        ]);
    });

    it('keeps a self-scaled season’s repeating build/deload cycle as two phases, not four', () => {
        const phases = phasesOf([
            week({ phase: 'build', type: 'history' }),
            week({
                week_start: '2026-06-22',
                phase: 'deload',
                type: 'current',
            }),
            week({
                week_start: '2026-06-29',
                phase: 'build',
                type: 'lookahead',
            }),
        ]);

        expect(phases.map((p) => p.key)).toEqual(['build', 'deload']);
    });
});

describe('clampSummary', () => {
    it('names the eased session, its distance and its pace', () => {
        expect(
            clampSummary(
                {
                    session_type: 'easy',
                    distance_km: 5.9,
                    pace_sec_per_km: 450,
                    note: 'n',
                },
                9.1,
            ),
        ).toBe('easy · 5.9 km · 7:30/km');
    });

    /**
     * A Tempo/Interval step-down keeps the original core km and only drops the
     * intensity, so printing the distance again repeats the line above it and
     * reads as a rendering bug rather than as "same distance, easier pace".
     */
    it('drops the distance when the clamp left it alone', () => {
        expect(
            clampSummary(
                {
                    session_type: 'easy',
                    distance_km: 4.7,
                    pace_sec_per_km: 502,
                    note: 'n',
                },
                4.7,
            ),
        ).toBe('easy · 8:22/km');
    });

    it('omits the pace when there is no VDOT estimate to size one', () => {
        expect(
            clampSummary(
                {
                    session_type: 'rest',
                    distance_km: 0,
                    pace_sec_per_km: null,
                    note: 'n',
                },
                9.1,
            ),
        ).toBe('rest · 0 km');
    });
});
