import { Flag } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import type { PlanSessionSegment } from '@/types/inertia';

import type { PlanDay, SeasonSummaryWeek } from './plan';

import {
    dayStatusGlyph,
    SESSION_TYPE_ICON,
    SESSION_TYPE_LABEL,
    STATUS_GLYPH,
    complianceTally,
    computeAdherence,
    deltaDirection,
    easedFromDelta,
    fallOffTiltWhy,
    generalZoneSpan,
    isRaceWeek,
    judgedDayResult,
    paceEaseDelta,
    paceLabel,
    phaseGroupKey,
    phasesOf,
    prescriptionWhy,
    sessionHint,
    sessionLabel,
    sessionPurpose,
    sessionShape,
    volumeAdjustedFrom,
    weekdayLabel,
    weekRangeLabel,
    workLabel,
} from './plan';

function week(overrides: Partial<SeasonSummaryWeek> = {}): SeasonSummaryWeek {
    return {
        week_start: '2026-06-15',
        phase: 'base',
        zone: 'block',
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

    it('caps each day before averaging so an overreach cannot paper over a miss', () => {
        expect(
            computeAdherence([
                { compliance_score: 161 },
                { compliance_score: 0 },
            ]),
        ).toBe(50);
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

describe('complianceTally', () => {
    it('counts each graded verdict in a fixed order and leaves the rest out', () => {
        expect(
            complianceTally([
                { status: 'missed' },
                { status: 'done' },
                { status: 'planned' },
                { status: 'missed' },
                { status: 'skip' },
            ]),
        ).toBe('1 done · 2 missed');
    });

    it('is empty before any day is graded', () => {
        expect(complianceTally([{ status: 'planned' }])).toBe('');
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

    it('merges every general-zone week into one "general" entry, ignoring its own build/deload phase', () => {
        const phases = phasesOf([
            week({ zone: 'general', phase: 'build', type: 'history' }),
            week({
                week_start: '2026-06-22',
                zone: 'general',
                phase: 'deload',
                type: 'current',
            }),
            week({
                week_start: '2026-06-29',
                zone: 'block',
                phase: 'base',
                type: 'lookahead',
            }),
        ]);

        expect(phases.map((p) => p.key)).toEqual(['general', 'base']);
        expect(phases[0].state).toBe('current');
    });

    it('puts the general entry first, ahead of the block phases it precedes', () => {
        const phases = phasesOf([
            week({ zone: 'general', phase: 'build' }),
            week({
                week_start: '2026-06-22',
                zone: 'block',
                phase: 'base',
                type: 'current',
            }),
        ]);

        expect(phases.map((p) => p.key)).toEqual(['general', 'base']);
    });
});

describe('phaseGroupKey', () => {
    it('reads a block week’s own phase', () => {
        expect(phaseGroupKey(week({ zone: 'block', phase: 'peak' }))).toBe(
            'peak',
        );
    });

    it('collapses any general-zone week to the shared "general" key, whatever its own phase', () => {
        expect(phaseGroupKey(week({ zone: 'general', phase: 'build' }))).toBe(
            'general',
        );
        expect(phaseGroupKey(week({ zone: 'general', phase: 'deload' }))).toBe(
            'general',
        );
    });
});

describe('generalZoneSpan', () => {
    it('spans the first general week’s Monday to the last one’s Sunday', () => {
        expect(
            generalZoneSpan([
                week({ week_start: '2026-06-15', zone: 'general' }),
                week({ week_start: '2026-06-22', zone: 'general' }),
                week({ week_start: '2026-06-29', zone: 'block' }),
            ]),
        ).toEqual({ start: '2026-06-15', end: '2026-06-28' });
    });

    it('returns null when the season has no general weeks', () => {
        expect(
            generalZoneSpan([
                week({ week_start: '2026-06-15', zone: 'block' }),
            ]),
        ).toBeNull();
    });
});

describe('deltaDirection', () => {
    it('reads an increase as up', () => {
        expect(deltaDirection(3.6, 4.1)).toBe('up');
    });

    it('reads a decrease as down', () => {
        expect(deltaDirection(5.9, 4.1)).toBe('down');
    });
});

describe('easedFromDelta', () => {
    it('names the type and distance change together when both moved', () => {
        expect(
            easedFromDelta(
                { session_type: 'tempo', distance_km: 5.9, voice: null },
                planDay({ session_type: 'easy', distance_km: 4.1 }),
            ),
        ).toEqual({
            typeFrom: 'tempo',
            typeTo: 'easy',
            distanceFrom: '5.9',
            distanceTo: '4.1',
            direction: 'down',
        });
    });

    /** Intensity-only: the distance did not move, so repeating it reads as a bug. */
    it('names only the type when the distance was held', () => {
        expect(
            easedFromDelta(
                { session_type: 'tempo', distance_km: null, voice: null },
                planDay({ session_type: 'easy', distance_km: 4.1 }),
            ),
        ).toEqual({
            typeFrom: 'tempo',
            typeTo: 'easy',
            distanceFrom: null,
            distanceTo: '4.1',
            direction: 'down',
        });
    });

    it('names no type change when the type held', () => {
        expect(
            easedFromDelta(
                { session_type: 'easy', distance_km: 5.9, voice: null },
                planDay({ session_type: 'easy', distance_km: 4.1 }),
            ).typeFrom,
        ).toBeNull();
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
        asked_km: 8,
        pinned: false,
        skipped: false,
        status: 'planned',
        compliance_score: null,
        ran_anyway: false,
        prescribed_km: null,
        prescription_reason: null,
        fall_off_tilt: null,
        goal_pace: null,
        time_trial: null,
        advice_note: null,
        eased_from: null,
        pace_eased_from: null,
        credit_note: null,
        ran_hot: false,
        result_note: null,
        ran_pace_sec_per_km: null,
        actual_km: null,
        credited_km: null,
        activities: [],
        ...overrides,
    };
}

describe('judgedDayResult', () => {
    it('shows nothing for a day still to come', () => {
        expect(judgedDayResult(planDay())).toBeNull();
    });

    it('pairs what a judged day asked for with what was run, each with its own pace', () => {
        expect(
            judgedDayResult(
                planDay({
                    prescribed_km: 6,
                    actual_km: 5.4,
                    ran_pace_sec_per_km: 403,
                }),
            ),
        ).toEqual({
            askedKm: 6,
            askedPace: '6:00/km',
            ranKm: 5.4,
            ranPace: '6:43/km',
        });
    });

    it('reads a judged day nothing was run on as zero, not as blank', () => {
        expect(judgedDayResult(planDay({ prescribed_km: 6 }))?.ranKm).toBe(0);
    });

    it('drops the asked pace on a rest day with no prescribed pace', () => {
        expect(
            judgedDayResult(
                planDay({
                    session_type: 'rest',
                    segments: [],
                    prescribed_km: 0,
                    ran_pace_sec_per_km: 403,
                }),
            )?.askedPace,
        ).toBeNull();
    });

    it('drops the ran pace when the credited runs carry no moving time', () => {
        expect(
            judgedDayResult(
                planDay({ prescribed_km: 6, ran_pace_sec_per_km: null }),
            )?.ranPace,
        ).toBeNull();
    });
});

describe('volumeAdjustedFrom', () => {
    it("reports the original ask once the week's redistribution moves the day off it", () => {
        expect(
            volumeAdjustedFrom(planDay({ distance_km: 3, asked_km: 8 })),
        ).toBe(8);
    });

    it('reports nothing when redistribution left the day where it was', () => {
        expect(
            volumeAdjustedFrom(planDay({ distance_km: 8, asked_km: 8 })),
        ).toBeNull();
    });

    it('ignores a resize under half a kilometre, float error included', () => {
        expect(
            volumeAdjustedFrom(planDay({ distance_km: 10.3, asked_km: 10.4 })),
        ).toBeNull();
        expect(
            volumeAdjustedFrom(planDay({ distance_km: 4.1, asked_km: 3.6 })),
        ).toBe(3.6);
    });

    it('ignores rounding-sized drift', () => {
        expect(
            volumeAdjustedFrom(planDay({ distance_km: 8, asked_km: 8.02 })),
        ).toBeNull();
    });

    it('defers to the settled prescribed/actual figures once the day is graded', () => {
        expect(
            volumeAdjustedFrom(
                planDay({ distance_km: 3, asked_km: 8, prescribed_km: 8 }),
            ),
        ).toBeNull();
    });

    it('never flags a rest day', () => {
        expect(
            volumeAdjustedFrom(
                planDay({
                    session_type: 'rest',
                    distance_km: 0,
                    asked_km: 8,
                }),
            ),
        ).toBeNull();
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

    it('keeps reading the hard set when easy running follows it', () => {
        expect(
            paceLabel(
                planDay({
                    segments: [
                        {
                            key: 'main',
                            minutes: 25,
                            zone: 'Z4',
                            pace_label: 'threshold',
                            km: 5,
                            pace_sec_per_km: 300,
                        },
                        {
                            key: 'easy',
                            minutes: 20,
                            zone: 'Z2',
                            pace_label: 'easy',
                            km: 3,
                            pace_sec_per_km: 400,
                        },
                    ],
                }),
            ),
        ).toBe('5:00/km');
    });

    it('has no pace to read on a rest day', () => {
        expect(paceLabel(planDay({ session_type: 'rest', segments: [] }))).toBe(
            null,
        );
    });
});

describe('paceEaseDelta', () => {
    it("pairs the original pace with the day's own (already eased) pace", () => {
        expect(
            paceEaseDelta({ pace_sec_per_km: 360, voice: null }, planDay()),
        ).toEqual({ from: '6:00', to: '6:00/km' });

        expect(
            paceEaseDelta(
                { pace_sec_per_km: 360, voice: null },
                planDay({
                    segments: [
                        {
                            key: 'main',
                            minutes: 50,
                            zone: 'Z2',
                            pace_label: 'easy',
                            km: 5.2,
                            pace_sec_per_km: 375,
                        },
                    ],
                }),
            ),
        ).toEqual({ from: '6:00', to: '6:15/km' });
    });

    /**
     * Regression: `eased_pace_sec_per_km` is the SLOW END of the easy band
     * (`RestClampRecorder` writes `TrainingPaceCalculator::easySlowEndFromVdotResult()`
     * there) and `PlanRenderer` builds the day's own `segments` from it — so
     * the day's own pace (what the row reads via `corePaceSecPerKm`) is
     * always the EASED, slower figure, never the original. `pace_eased_from`
     * is independently recomputed from the athlete's un-eased paces, so it is
     * always the faster, replaced one. A pace ease is never a speed-up: `to`
     * (the day's own pace) must read slower than `from` (the replaced one).
     */
    it('reads the day’s own (eased) pace as the slower figure, never the original', () => {
        const delta = paceEaseDelta(
            { pace_sec_per_km: 380, voice: null }, // original: 6:20/km
            planDay({
                segments: [
                    {
                        key: 'main',
                        minutes: 22.6,
                        zone: 'Z2',
                        pace_label: 'easy',
                        km: 2.7,
                        pace_sec_per_km: 502, // eased slow end: 8:22/km
                    },
                ],
            }),
        );

        expect(delta).toEqual({ from: '6:20', to: '8:22/km' });
    });

    it('has nothing to show without a recorded original pace', () => {
        expect(
            paceEaseDelta({ pace_sec_per_km: null, voice: null }, planDay()),
        ).toBeNull();
    });

    it('has nothing to show when the day itself carries no pace', () => {
        expect(
            paceEaseDelta(
                { pace_sec_per_km: 360, voice: null },
                planDay({ session_type: 'rest', segments: [] }),
            ),
        ).toBeNull();
    });
});

describe('race day', () => {
    it('names and marks race day, so the goal race never reads as an ordinary session', () => {
        expect(SESSION_TYPE_LABEL.race).toBe('race day');
        expect(SESSION_TYPE_ICON.race).toBe(Flag);
    });
});

describe('sessionShape', () => {
    const seg = (
        key: PlanSessionSegment['key'],
        zone: string,
        minutes: number | null,
        km: number | null,
        pace: number | null = null,
    ): PlanSessionSegment => ({
        key,
        zone,
        minutes,
        km,
        pace_label: zone <= 'Z2' ? 'easy' : 'interval',
        pace_sec_per_km: pace,
    });

    it('collapses repeated reps into one count', () => {
        expect(
            sessionShape([
                seg('warmup', 'Z2', 15, 2.3),
                seg('interval', 'Z5', 3, 0.7, 260),
                seg('recovery', 'Z2', 2, 0.3),
                seg('interval', 'Z5', 3, 0.7, 260),
                seg('recovery', 'Z2', 2, 0.3),
                seg('interval', 'Z5', 3, 0.7, 260),
                seg('main', 'Z2', 20, 3.1),
            ]),
        ).toBe(
            '15 min warm-up → 3 × 3 min at 4:20/km, 2 min jog between → 3.1 km easy',
        );
    });

    it('says nothing for a single block the headline already covers', () => {
        expect(sessionShape([seg('main', 'Z2', 50, 7.3)])).toBeNull();
    });
});

describe('sessionPurpose', () => {
    it('only calls a tempo comfortably hard when it has work above Z2', () => {
        expect(
            sessionPurpose(
                planDay({
                    session_type: 'tempo',
                    segments: [
                        {
                            key: 'main',
                            minutes: 40,
                            zone: 'Z2',
                            pace_label: 'easy',
                            km: 6,
                            pace_sec_per_km: 400,
                        },
                    ],
                }),
            ),
        ).toBeNull();
    });
});

describe('prescriptionWhy', () => {
    const easyBlock = [
        {
            key: 'main' as const,
            minutes: 40,
            zone: 'Z2',
            pace_label: 'easy' as const,
            km: 6,
            pace_sec_per_km: 400,
        },
    ];

    it('hides engine placeholders and unknown strings', () => {
        expect(
            prescriptionWhy(planDay({ prescription_reason: 'easy volume' })),
        ).toBeNull();
        expect(
            prescriptionWhy(
                planDay({
                    prescription_reason: 'resolved coaching prescription',
                }),
            ),
        ).toBeNull();
        expect(
            prescriptionWhy(planDay({ prescription_reason: null })),
        ).toBeNull();
    });

    it('drops a dose reason once the day fell back to an easy block', () => {
        expect(
            prescriptionWhy(
                planDay({
                    session_type: 'tempo',
                    segments: easyBlock,
                    prescription_reason:
                        'progressed after the latest comparable session was hit',
                }),
            ),
        ).toBeNull();
    });

    it('keeps the kept-easy reason on the easy day it explains', () => {
        expect(
            prescriptionWhy(
                planDay({
                    session_type: 'easy',
                    segments: easyBlock,
                    prescription_reason:
                        'easy to preserve recovery between hard days',
                }),
            ),
        ).toBe('kept easy. too close to another hard day.');
    });
});

describe('dayStatusGlyph', () => {
    it('shows no glyph for an upcoming, still-planned day', () => {
        expect(dayStatusGlyph(planDay({ status: 'planned' }))).toBeNull();
    });

    it('shows no glyph for a rest day kept as rest', () => {
        expect(
            dayStatusGlyph(
                planDay({ session_type: 'rest', status: 'planned' }),
            ),
        ).toBeNull();
    });

    it('grades a rest day run anyway like any other graded day', () => {
        expect(
            dayStatusGlyph(
                planDay({
                    session_type: 'rest',
                    status: 'done',
                    ran_anyway: true,
                }),
            ),
        ).toBe(STATUS_GLYPH.done);
    });

    it('reads an excused upcoming day as skipped ahead of its server-side status', () => {
        expect(
            dayStatusGlyph(planDay({ status: 'planned', skipped: true })),
        ).toBe(STATUS_GLYPH.skip);
    });

    it.each([
        ['done', STATUS_GLYPH.done],
        ['partial', STATUS_GLYPH.partial],
        ['missed', STATUS_GLYPH.missed],
        ['overreached', STATUS_GLYPH.overreached],
    ] as const)('maps status %s to its glyph', (status, glyph) => {
        expect(dayStatusGlyph(planDay({ status }))).toBe(glyph);
    });
});

describe('fallOffTiltWhy', () => {
    it('names the tilt behind each session it moves', () => {
        expect(
            fallOffTiltWhy(
                planDay({ session_type: 'tempo', fall_off_tilt: 'endurance' }),
            ),
        ).toBe('threshold this week: your pace fades over longer distances.');
        expect(
            fallOffTiltWhy(
                planDay({ session_type: 'long', fall_off_tilt: 'endurance' }),
            ),
        ).toBe(
            'a little longer this week: your pace fades over longer distances.',
        );
        expect(
            fallOffTiltWhy(
                planDay({ session_type: 'interval', fall_off_tilt: 'speed' }),
            ),
        ).toBe(
            'intervals this week: you hold pace well over longer distances, so speed has the most room to grow.',
        );
    });

    it('says nothing on an untilted day or a pairing no tilt produces', () => {
        expect(
            fallOffTiltWhy(
                planDay({ session_type: 'tempo', fall_off_tilt: null }),
            ),
        ).toBeNull();
        expect(
            fallOffTiltWhy(
                planDay({ session_type: 'easy', fall_off_tilt: 'endurance' }),
            ),
        ).toBeNull();
    });
});

describe('sessionLabel', () => {
    it('names goal-pace work by its own label and every other day by its type', () => {
        expect(
            sessionLabel(
                planDay({ session_type: 'interval', goal_pace: '10k' }),
            ),
        ).toBe('goal pace');
        expect(
            sessionLabel(planDay({ session_type: 'tempo', goal_pace: 'half' })),
        ).toBe('goal pace');
        expect(
            sessionLabel(planDay({ session_type: 'tempo', goal_pace: null })),
        ).toBe('tempo');
        expect(
            sessionLabel(planDay({ session_type: 'long', goal_pace: null })),
        ).toBe('long run');
    });
});

describe('a time trial', () => {
    const trial = { distance_m: 5_000, aim_time_sec: 1_410 };

    it('is named by its own label ahead of goal pace and its type', () => {
        expect(
            sessionLabel(
                planDay({
                    session_type: 'interval',
                    goal_pace: '5k',
                    time_trial: trial,
                }),
            ),
        ).toBe('time trial');
        expect(workLabel(planDay({ time_trial: trial }))).toBe('time trial');
        expect(workLabel(planDay({ goal_pace: '10k' }))).toBe('goal pace');
        expect(workLabel(planDay({}))).toBeUndefined();
    });

    it('carries one practical hint, on a trial day only', () => {
        expect(sessionHint(planDay({ time_trial: trial }))).toBe(
            'warm up first like you would before a race, then record the trial as its own run if you can.',
        );
        expect(sessionHint(planDay({}))).toBeNull();
    });

    it('says the time to aim around and what the trial is for', () => {
        expect(
            sessionPurpose(
                planDay({ session_type: 'interval', time_trial: trial }),
            ),
        ).toBe(
            'aim around 23:30. checks your fitness so your paces stay honest.',
        );
    });
});

describe('sessionPurpose on goal-pace work', () => {
    it('says which goal pace the session rehearses', () => {
        expect(
            sessionPurpose(
                planDay({ session_type: 'interval', goal_pace: '5k' }),
            ),
        ).toBe('rehearsing your 5K goal pace.');
        expect(
            sessionPurpose(
                planDay({ session_type: 'tempo', goal_pace: '10k' }),
            ),
        ).toBe('rehearsing your 10K goal pace.');
        expect(
            sessionPurpose(
                planDay({ session_type: 'tempo', goal_pace: 'half' }),
            ),
        ).toBe('rehearsing your half marathon goal pace.');
        expect(
            sessionPurpose(
                planDay({ session_type: 'tempo', goal_pace: 'marathon' }),
            ),
        ).toBe('rehearsing your marathon goal pace.');
    });
});
