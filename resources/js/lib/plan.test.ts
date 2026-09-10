import { Flag } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import type { PlanDay, SeasonSummaryWeek } from './plan';

import {
    SESSION_TYPE_ICON,
    SESSION_TYPE_LABEL,
    clampSummary,
    computeAdherence,
    isRaceWeek,
    kmLabel,
    paceLabel,
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
                    label: 'eased today',
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
                    label: 'eased today',
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
                    label: 'eased today',
                },
                9.1,
            ),
        ).toBe('rest · 0 km');
    });
});

describe('isRaceWeek', () => {
    it('marks the week the race date falls in, from monday to sunday', () => {
        expect(isRaceWeek('2026-06-15', '2026-06-15')).toBe(true);
        expect(isRaceWeek('2026-06-15', '2026-06-21')).toBe(true);
        expect(isRaceWeek('2026-06-15', '2026-06-18')).toBe(true);
    });

    it('leaves the weeks either side of it unmarked', () => {
        expect(isRaceWeek('2026-06-15', '2026-06-14')).toBe(false);
        expect(isRaceWeek('2026-06-15', '2026-06-22')).toBe(false);
    });

    it('marks nothing when no race is set', () => {
        expect(isRaceWeek('2026-06-15', null)).toBe(false);
    });
});

function planDay(overrides: Partial<PlanDay> = {}): PlanDay {
    return {
        id: 1,
        date: '2026-06-12',
        phase: 'build',
        session_type: 'easy',
        segments: [
            {
                key: 'main',
                minutes: 48,
                zone: 'Z2',
                pace_label: 'easy',
                km: 5.2,
                pace_sec_per_km: 360,
            },
        ],
        distance_km: 8,
        pinned: false,
        skipped: false,
        status: 'planned',
        compliance_score: null,
        ran_anyway: false,
        prescribed_km: null,
        clamp: null,
        actual_km: null,
        activities: [],
        ...overrides,
    };
}

describe('kmLabel', () => {
    it('reads a day still to come as the ask alone', () => {
        expect(kmLabel(planDay())).toBe('8 km');
    });

    it('states what a judged day asked for and what was run against it', () => {
        expect(kmLabel(planDay({ prescribed_km: 6, actual_km: 5.4 }))).toBe(
            '6 km asked · 5.4 km run',
        );
    });

    it('reads a judged day nothing was run on as zero, not as blank', () => {
        expect(kmLabel(planDay({ prescribed_km: 6 }))).toBe(
            '6 km asked · 0 km run',
        );
    });
});

describe('paceLabel', () => {
    it("reads the core set's pace", () => {
        expect(paceLabel(planDay())).toBe('6:00/km');
    });

    it('reads an interval day off its rep segment', () => {
        expect(
            paceLabel(
                planDay({
                    segments: [
                        {
                            key: 'warmup',
                            minutes: 10,
                            zone: 'Z1',
                            pace_label: 'easy',
                            km: 2,
                            pace_sec_per_km: 420,
                        },
                        {
                            key: 'interval',
                            minutes: 4,
                            zone: 'Z5',
                            pace_label: 'interval',
                            km: 1,
                            pace_sec_per_km: 240,
                        },
                    ],
                }),
            ),
        ).toBe('4:00/km');
    });

    it('has no pace to read on a rest day', () => {
        expect(paceLabel(planDay({ session_type: 'rest', segments: [] }))).toBe(
            null,
        );
    });
});

describe('race day', () => {
    it('names and marks race day, so the goal race never reads as an ordinary session', () => {
        expect(SESSION_TYPE_LABEL.race).toBe('race day');
        expect(SESSION_TYPE_ICON.race).toBe(Flag);
    });
});
