import {
    ArrowRight,
    ArrowRightLeft,
    Bed,
    ChevronDown,
    Ellipsis,
    Feather,
    Flame,
    RefreshCw,
    SkipForward,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';

import { FaceIcon } from '@/components/FaceIcon';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';

import { AiReplanPill } from './AiReplanPill';
import { ScheduleRaceTabs } from './ScheduleRaceTabs';

const PHASES = [
    { key: 'base', label: 'base' },
    { key: 'build', label: 'build' },
    { key: 'peak', label: 'peak' },
    { key: 'taper', label: 'taper' },
] as const;

// Each phase gets its own identity color (validated distinct from this
// page's status palette and from each other via the dataviz skill's
// contrast script) — state (current/done/upcoming) is then conveyed by
// fill vs. outline, not by hue.
const PHASE_COLOR: Record<WeekPhase, string> = {
    base: '#0d9488',
    build: '#1e6fe0',
    peak: '#a21caf',
    taper: '#db2777',
};

type WeekPhase = (typeof PHASES)[number]['key'];
type SeasonWeek = {
    week: number;
    phase: WeekPhase;
    range: string;
    km: number;
    sessions: number;
    status: 'done' | 'current' | 'upcoming';
    focus: string;
};
type SeasonWeekStatus = 'done' | 'current' | 'upcoming';
type DayStatus =
    'done' | 'partial' | 'missed' | 'overreached' | 'skip' | 'upcoming';
type ZoneLevel = 1 | 2 | 3 | 4 | 5;
type SegmentKey = 'warmup' | 'main' | 'interval' | 'recovery' | 'cooldown';
type SessionSegment = {
    key: SegmentKey;
    minutes: number;
    zone: ZoneLevel;
    sub: string;
};
type DayType = 'easy' | 'tempo' | 'intervals' | 'long run' | 'rest';
type PlanDay = {
    wd: (typeof ALL_WEEKDAYS)[number];
    type: DayType;
    summary: string | null;
    status: DayStatus;
    score: number | null;
    today: boolean;
    plannedKm: number;
    actualKm: number | null;
    activity: { summary: string } | null;
    segments: SessionSegment[];
    detail: string;
};

// Reuses the real app's HR-zone ramp (resources/js/lib/chartTokens.ts): teal→green→amber→orange→red, Z1→Z5.
const ZONE_COLOR: Record<ZoneLevel, string> = {
    1: '#35c6da',
    2: '#2f956a',
    3: '#d99a1a',
    4: '#c46f1c',
    5: '#b8302f',
};
const ZONE_HEIGHT_PCT: Record<ZoneLevel, number> = {
    1: 32,
    2: 52,
    3: 70,
    4: 86,
    5: 100,
};
const SEGMENT_LABEL: Record<SegmentKey, string> = {
    warmup: 'warmup',
    main: 'main set',
    interval: 'interval',
    recovery: 'recovery',
    cooldown: 'cooldown',
};
const TYPE_ICON: Record<DayType, typeof Flame> = {
    tempo: Flame,
    intervals: Flame,
    easy: Feather,
    'long run': Feather,
    rest: Bed,
};
const ALL_WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const;

const SEASON_WEEKS: SeasonWeek[] = [
    {
        week: 1,
        phase: 'base',
        range: '12–18 jun',
        km: 28,
        sessions: 4,
        status: 'done',
        focus: 'easy mileage, building the habit.',
    },
    {
        week: 2,
        phase: 'base',
        range: '19–25 jun',
        km: 31,
        sessions: 5,
        status: 'done',
        focus: 'steady aerobic volume, no surprises.',
    },
    {
        week: 3,
        phase: 'base',
        range: '26 jun–2 jul',
        km: 33,
        sessions: 5,
        status: 'done',
        focus: 'first strides session added to an easy day.',
    },
    {
        week: 4,
        phase: 'base',
        range: '3–9 jul',
        km: 29,
        sessions: 4,
        status: 'done',
        focus: 'deload — a lighter week before the push back up.',
    },
    {
        week: 5,
        phase: 'base',
        range: '10–16 jul',
        km: 35,
        sessions: 5,
        status: 'done',
        focus: 'longest base-phase long run yet.',
    },
    {
        week: 6,
        phase: 'base',
        range: '17–23 jul',
        km: 34,
        sessions: 5,
        status: 'current',
        focus: 'tempo tuesday, long run saturday — the last full week before build ramps up.',
    },
    {
        week: 7,
        phase: 'build',
        range: '24–30 jul',
        km: 38,
        sessions: 5,
        status: 'upcoming',
        focus: 'tempo work steps up a notch.',
    },
    {
        week: 8,
        phase: 'build',
        range: '31 jul–6 aug',
        km: 40,
        sessions: 5,
        status: 'upcoming',
        focus: 'first interval session of the block.',
    },
    {
        week: 9,
        phase: 'peak',
        range: '7–13 aug',
        km: 44,
        sessions: 5,
        status: 'upcoming',
        focus: 'highest volume of the whole block.',
    },
    {
        week: 10,
        phase: 'peak',
        range: '14–20 aug',
        km: 42,
        sessions: 5,
        status: 'upcoming',
        focus: 'race-pace long run.',
    },
    {
        week: 11,
        phase: 'taper',
        range: '21–27 aug',
        km: 30,
        sessions: 4,
        status: 'upcoming',
        focus: 'volume drops, effort stays sharp.',
    },
    {
        week: 12,
        phase: 'taper',
        range: '28 aug–4 sep',
        km: 18,
        sessions: 3,
        status: 'upcoming',
        focus: 'race week — fresh legs.',
    },
];

const STATUS_STYLE: Record<DayStatus, string> = {
    done: 'text-icon-accent',
    partial: 'text-citrus',
    missed: 'text-destructive',
    overreached: 'text-[#d97706]',
    skip: 'text-foreground',
    upcoming: '',
};
const STATUS_BAR_FILL: Record<DayStatus, string> = {
    done: 'bg-icon-accent',
    partial: 'bg-citrus',
    missed: 'bg-destructive',
    overreached: 'bg-[#d97706]',
    skip: 'bg-foreground',
    upcoming: '',
};

const WEEK_DAYS: PlanDay[] = [
    {
        wd: 'mon',
        type: 'easy' as DayType,
        summary: '6 km · 5:48–6:05/km',
        status: 'overreached' as DayStatus,
        score: 65,
        today: false,
        plannedKm: 6,
        actualKm: 8.1,
        activity: { summary: '8.1 km · 45:20' } as { summary: string } | null,
        segments: [
            { key: 'warmup', minutes: 5, zone: 1, sub: '≤6:20/km · easy' },
            { key: 'main', minutes: 35, zone: 2, sub: '5:48–6:05/km' },
            { key: 'cooldown', minutes: 5, zone: 1, sub: '≤6:20/km · easy' },
        ] as SessionSegment[],
        detail: 'felt good and kept going — ended up well past the prescribed distance. fine once, worth watching if it becomes a pattern.',
    },
    {
        wd: 'tue',
        type: 'intervals' as DayType,
        summary: '6 × 3min @ z4–z5',
        status: 'partial' as DayStatus,
        score: 68,
        today: false,
        plannedKm: 9.5,
        actualKm: 6.5,
        activity: { summary: '6.5 km · 41:10' } as { summary: string } | null,
        segments: [
            { key: 'warmup', minutes: 10, zone: 1, sub: '≤6:15/km · easy' },
            { key: 'interval', minutes: 3, zone: 5, sub: '~3:50/km · hard' },
            { key: 'recovery', minutes: 2, zone: 1, sub: 'jog easy' },
            { key: 'interval', minutes: 3, zone: 5, sub: '~3:50/km · hard' },
            { key: 'recovery', minutes: 2, zone: 1, sub: 'jog easy' },
            { key: 'interval', minutes: 3, zone: 5, sub: '~3:50/km · hard' },
            { key: 'recovery', minutes: 2, zone: 1, sub: 'jog easy' },
            { key: 'interval', minutes: 3, zone: 5, sub: '~3:50/km · hard' },
            { key: 'recovery', minutes: 2, zone: 1, sub: 'jog easy' },
            { key: 'interval', minutes: 3, zone: 5, sub: '~3:50/km · hard' },
            { key: 'recovery', minutes: 2, zone: 1, sub: 'jog easy' },
            { key: 'interval', minutes: 3, zone: 5, sub: '~3:50/km · hard' },
            { key: 'cooldown', minutes: 10, zone: 1, sub: '≤6:15/km · easy' },
        ] as SessionSegment[],
        detail: 'cut it to 4 of 6 planned reps — legs were flat after monday, still a solid session.',
    },
    {
        wd: 'wed',
        type: 'rest' as DayType,
        summary: null,
        status: 'done' as DayStatus,
        score: null,
        today: false,
        plannedKm: 0,
        actualKm: null,
        activity: { summary: '4.2 km · 26:08' } as { summary: string } | null,
        segments: [] as SessionSegment[],
        detail: 'full rest, planned — logged a light jog anyway. no harm, just noting it.',
    },
    {
        wd: 'thu',
        type: 'easy' as DayType,
        summary: '6 km · 5:38–5:55/km',
        status: 'upcoming' as DayStatus,
        score: null,
        today: true,
        plannedKm: 6,
        actualKm: null,
        activity: null as { summary: string } | null,
        segments: [
            { key: 'warmup', minutes: 5, zone: 1, sub: '≤6:10/km · easy' },
            { key: 'main', minutes: 35, zone: 2, sub: '5:38–5:55/km' },
            { key: 'cooldown', minutes: 5, zone: 1, sub: '≤6:10/km · easy' },
        ] as SessionSegment[],
        detail: 'an easy shakeout between two harder days — keep it conversational.',
    },
    {
        wd: 'fri',
        type: 'rest' as DayType,
        summary: null,
        status: 'upcoming' as DayStatus,
        score: null,
        today: false,
        plannedKm: 0,
        actualKm: null,
        activity: null as { summary: string } | null,
        segments: [] as SessionSegment[],
        detail: "rest ahead of saturday's long run.",
    },
    {
        wd: 'sat',
        type: 'long run' as DayType,
        summary: '14 km · 5:28–5:48/km',
        status: 'upcoming' as DayStatus,
        score: null,
        today: false,
        plannedKm: 14,
        actualKm: null,
        activity: null as { summary: string } | null,
        segments: [
            { key: 'warmup', minutes: 10, zone: 1, sub: '≤6:00/km · easy' },
            { key: 'main', minutes: 79, zone: 2, sub: '5:28–5:48/km' },
            { key: 'cooldown', minutes: 10, zone: 1, sub: '≤6:00/km · easy' },
        ] as SessionSegment[],
        detail: "the week's anchor — steady effort, fuel and hydrate like race day.",
    },
    {
        wd: 'sun',
        type: 'rest' as DayType,
        summary: null,
        status: 'upcoming' as DayStatus,
        score: null,
        today: false,
        plannedKm: 0,
        actualKm: null,
        activity: null as { summary: string } | null,
        segments: [] as SessionSegment[],
        detail: 'recovery. an easy walk is fine, just no running.',
    },
];

// One example of every status, for reviewing the compliance system without
// needing to click "skip" or wait for a real missed/overreached day to occur.
const STATUS_SHOWCASE_DAYS: PlanDay[] = [
    {
        wd: 'mon',
        type: 'easy' as DayType,
        summary: '6 km · 5:48–6:05/km',
        status: 'done' as DayStatus,
        score: 100,
        today: false,
        plannedKm: 6,
        actualKm: 6,
        activity: { summary: '6.0 km · 34:52' },
        segments: [
            { key: 'warmup', minutes: 5, zone: 1, sub: '≤6:20/km · easy' },
            { key: 'main', minutes: 35, zone: 2, sub: '5:48–6:05/km' },
            { key: 'cooldown', minutes: 5, zone: 1, sub: '≤6:20/km · easy' },
        ] as SessionSegment[],
        detail: 'right on target — this is what "done" looks like.',
    },
    {
        wd: 'tue',
        type: 'tempo' as DayType,
        summary: '8 km · 4:52–5:05/km',
        status: 'partial' as DayStatus,
        score: 68,
        today: false,
        plannedKm: 8,
        actualKm: 5.4,
        activity: { summary: '5.4 km · 27:30' },
        segments: [
            { key: 'warmup', minutes: 10, zone: 1, sub: '≤6:15/km · easy' },
            { key: 'main', minutes: 25, zone: 3, sub: '4:52–5:05/km' },
            { key: 'cooldown', minutes: 10, zone: 1, sub: '≤6:15/km · easy' },
        ] as SessionSegment[],
        detail: 'logged 5.4 of 8 km before cutting it short — still counts, just under target.',
    },
    {
        wd: 'wed',
        type: 'tempo' as DayType,
        summary: '8 km · 4:52–5:05/km',
        status: 'missed' as DayStatus,
        score: 12,
        today: false,
        plannedKm: 8,
        actualKm: 1,
        activity: { summary: '1.0 km · 6:40' },
        segments: [
            { key: 'warmup', minutes: 10, zone: 1, sub: '≤6:15/km · easy' },
            { key: 'main', minutes: 25, zone: 3, sub: '4:52–5:05/km' },
            { key: 'cooldown', minutes: 10, zone: 1, sub: '≤6:15/km · easy' },
        ] as SessionSegment[],
        detail: 'barely started before calling it — this is what a real miss looks like, not a no-show.',
    },
    {
        wd: 'thu',
        type: 'long run' as DayType,
        summary: '10 km · 5:28–5:48/km',
        status: 'overreached' as DayStatus,
        score: 50,
        today: false,
        plannedKm: 10,
        actualKm: 15,
        activity: { summary: '15.0 km · 82:40' },
        segments: [
            { key: 'warmup', minutes: 10, zone: 1, sub: '≤6:00/km · easy' },
            { key: 'main', minutes: 55, zone: 2, sub: '5:28–5:48/km' },
            { key: 'cooldown', minutes: 10, zone: 1, sub: '≤6:00/km · easy' },
        ] as SessionSegment[],
        detail: 'felt good and kept going 5km past the plan — this is "overreached".',
    },
    {
        wd: 'fri',
        type: 'easy' as DayType,
        summary: '6 km · 5:48–6:05/km',
        status: 'skip' as DayStatus,
        score: null,
        today: false,
        plannedKm: 6,
        actualKm: null,
        activity: null,
        segments: [
            { key: 'warmup', minutes: 5, zone: 1, sub: '≤6:20/km · easy' },
            { key: 'main', minutes: 35, zone: 2, sub: '5:48–6:05/km' },
            { key: 'cooldown', minutes: 5, zone: 1, sub: '≤6:20/km · easy' },
        ] as SessionSegment[],
        detail: "marked skip on purpose — busy day. doesn't count against the week the way a miss does.",
    },
    {
        wd: 'sat',
        type: 'rest' as DayType,
        summary: null,
        status: 'done' as DayStatus,
        score: null,
        today: false,
        plannedKm: 0,
        actualKm: null,
        activity: { summary: '4.2 km · 26:08' },
        segments: [] as SessionSegment[],
        detail: 'planned rest, ran anyway — this is the "ran anyway" note on a rest day.',
    },
    {
        wd: 'sun',
        type: 'rest' as DayType,
        summary: null,
        status: 'upcoming' as DayStatus,
        score: null,
        today: false,
        plannedKm: 0,
        actualKm: null,
        activity: null,
        segments: [] as SessionSegment[],
        detail: 'a plain rest day, nothing to report — the baseline case.',
    },
];

// Compact builder for a historical week's non-interactive-shaped days — avoids
// hand-writing the full warmup/main/cooldown segment array for every session.
type DayOutcome = {
    status: DayStatus;
    score: number | null;
    actualKm: number | null;
    detail: string;
};

// Compact builder for a historical week's days — avoids hand-writing the full
// warmup/main/cooldown segment array for every session.
function buildDay(
    wd: (typeof ALL_WEEKDAYS)[number],
    type: DayType,
    plan: { km: number; minutes: number; pace: string },
    outcome: DayOutcome,
): PlanDay {
    const { status, score, actualKm, detail } = outcome;
    if (type === 'rest') {
        return {
            wd,
            type,
            summary: null,
            status,
            score,
            today: false,
            plannedKm: 0,
            actualKm,
            activity: actualKm != null ? { summary: `${actualKm} km` } : null,
            segments: [],
            detail,
        };
    }
    const wuCd = type === 'tempo' || type === 'long run' ? 10 : 5;
    const zone: ZoneLevel = type === 'tempo' ? 3 : 2;
    return {
        wd,
        type,
        summary: `${plan.km} km · ${plan.pace}`,
        status,
        score,
        today: false,
        plannedKm: plan.km,
        actualKm,
        activity: actualKm != null ? { summary: `${actualKm} km` } : null,
        segments: [
            { key: 'warmup', minutes: wuCd, zone: 1, sub: 'easy' },
            { key: 'main', minutes: plan.minutes, zone, sub: plan.pace },
            { key: 'cooldown', minutes: wuCd, zone: 1, sub: 'easy' },
        ] as SessionSegment[],
        detail,
    };
}

const REST: { km: number; minutes: number; pace: string } = {
    km: 0,
    minutes: 0,
    pace: '',
};
const RESTED: DayOutcome = {
    status: 'done',
    score: null,
    actualKm: null,
    detail: 'rest.',
};

const WEEK1_DAYS: PlanDay[] = [
    buildDay(
        'mon',
        'easy',
        { km: 6, minutes: 35, pace: '5:55–6:10/km' },
        {
            status: 'done',
            score: 100,
            actualKm: 6,
            detail: 'first week, easy does it.',
        },
    ),
    buildDay(
        'tue',
        'easy',
        { km: 6, minutes: 35, pace: '5:55–6:10/km' },
        {
            status: 'done',
            score: 100,
            actualKm: 6,
            detail: 'another easy one — building the habit before adding intensity.',
        },
    ),
    buildDay('wed', 'rest', REST, RESTED),
    buildDay(
        'thu',
        'easy',
        { km: 6, minutes: 35, pace: '5:55–6:10/km' },
        {
            status: 'done',
            score: 100,
            actualKm: 6,
            detail: 'same shape as monday — consistency over variety this early.',
        },
    ),
    buildDay('fri', 'rest', REST, RESTED),
    buildDay(
        'sat',
        'long run',
        { km: 10, minutes: 58, pace: '5:50–6:10/km' },
        {
            status: 'done',
            score: 100,
            actualKm: 10,
            detail: 'first long run of the block — comfortably easy.',
        },
    ),
    buildDay('sun', 'rest', REST, RESTED),
];

const WEEK2_DAYS: PlanDay[] = [
    buildDay(
        'mon',
        'easy',
        { km: 5, minutes: 30, pace: '5:50–6:05/km' },
        {
            status: 'done',
            score: 100,
            actualKm: 5,
            detail: 'easy volume, no surprises.',
        },
    ),
    buildDay(
        'tue',
        'tempo',
        { km: 6, minutes: 30, pace: '4:58–5:12/km' },
        {
            status: 'done',
            score: 100,
            actualKm: 6,
            detail: 'first tempo of the block.',
        },
    ),
    buildDay('wed', 'rest', REST, RESTED),
    buildDay(
        'thu',
        'easy',
        { km: 5, minutes: 30, pace: '5:50–6:05/km' },
        { status: 'done', score: 100, actualKm: 5, detail: 'steady.' },
    ),
    buildDay(
        'fri',
        'easy',
        { km: 4, minutes: 24, pace: '5:50–6:05/km' },
        {
            status: 'done',
            score: 100,
            actualKm: 4,
            detail: 'a short one to round out the week.',
        },
    ),
    buildDay(
        'sat',
        'long run',
        { km: 11, minutes: 63, pace: '5:45–6:05/km' },
        {
            status: 'done',
            score: 100,
            actualKm: 11,
            detail: 'longer than last week — still comfortable.',
        },
    ),
    buildDay('sun', 'rest', REST, RESTED),
];

const WEEK3_DAYS: PlanDay[] = [
    buildDay(
        'mon',
        'easy',
        { km: 6, minutes: 35, pace: '5:50–6:05/km' },
        { status: 'done', score: 100, actualKm: 6, detail: 'easy.' },
    ),
    buildDay(
        'tue',
        'tempo',
        { km: 6, minutes: 30, pace: '4:55–5:10/km' },
        {
            status: 'partial',
            score: 72,
            actualKm: 4.3,
            detail: 'cut it short — legs weren’t there today, still counts.',
        },
    ),
    buildDay('wed', 'rest', REST, RESTED),
    buildDay(
        'thu',
        'easy',
        { km: 5, minutes: 30, pace: '5:50–6:05/km' },
        {
            status: 'done',
            score: 100,
            actualKm: 5,
            detail: 'first strides session added to the end of this one.',
        },
    ),
    buildDay(
        'fri',
        'easy',
        { km: 5, minutes: 30, pace: '5:50–6:05/km' },
        { status: 'done', score: 100, actualKm: 5, detail: 'easy.' },
    ),
    buildDay(
        'sat',
        'long run',
        { km: 11, minutes: 63, pace: '5:45–6:05/km' },
        {
            status: 'done',
            score: 100,
            actualKm: 11,
            detail: 'steady long run, same distance as last week.',
        },
    ),
    buildDay('sun', 'rest', REST, RESTED),
];

const WEEK4_DAYS: PlanDay[] = [
    buildDay(
        'mon',
        'easy',
        { km: 6, minutes: 35, pace: '5:50–6:05/km' },
        { status: 'done', score: 100, actualKm: 6, detail: 'easy.' },
    ),
    buildDay(
        'tue',
        'tempo',
        { km: 6, minutes: 30, pace: '4:58–5:12/km' },
        {
            status: 'skip',
            score: null,
            actualKm: null,
            detail: 'skipped on purpose — deload week, no point forcing a tempo.',
        },
    ),
    buildDay('wed', 'rest', REST, RESTED),
    buildDay(
        'thu',
        'easy',
        { km: 6, minutes: 35, pace: '5:50–6:05/km' },
        { status: 'done', score: 100, actualKm: 6, detail: 'easy.' },
    ),
    buildDay('fri', 'rest', REST, RESTED),
    buildDay(
        'sat',
        'long run',
        { km: 11, minutes: 63, pace: '5:45–6:05/km' },
        {
            status: 'done',
            score: 100,
            actualKm: 11,
            detail: 'a lighter week overall — this stayed comfortable.',
        },
    ),
    buildDay('sun', 'rest', REST, RESTED),
];

const WEEK5_DAYS: PlanDay[] = [
    buildDay(
        'mon',
        'easy',
        { km: 6, minutes: 35, pace: '5:50–6:05/km' },
        { status: 'done', score: 100, actualKm: 6, detail: 'easy.' },
    ),
    buildDay(
        'tue',
        'tempo',
        { km: 7, minutes: 35, pace: '4:55–5:10/km' },
        {
            status: 'done',
            score: 100,
            actualKm: 7,
            detail: 'solid tempo, pace held the whole way.',
        },
    ),
    buildDay('wed', 'rest', REST, RESTED),
    buildDay(
        'thu',
        'easy',
        { km: 6, minutes: 35, pace: '5:50–6:05/km' },
        { status: 'done', score: 100, actualKm: 6, detail: 'easy.' },
    ),
    buildDay(
        'fri',
        'easy',
        { km: 5, minutes: 30, pace: '5:50–6:05/km' },
        { status: 'done', score: 100, actualKm: 5, detail: 'easy.' },
    ),
    buildDay(
        'sat',
        'long run',
        { km: 12, minutes: 68, pace: '5:45–6:05/km' },
        {
            status: 'overreached',
            score: 67,
            actualKm: 16,
            detail: 'kept going — ended up doing the longest run of the block yet, 16 km instead of the planned 12.',
        },
    ),
    buildDay('sun', 'rest', REST, RESTED),
];

// Only weeks with an entry here get the full day-by-day breakdown when
// expanded — past + current. Future weeks stay summary-only: the actual
// day-level plan for a future week isn't decided yet, it adapts to how the
// weeks before it go.
const WEEKLY_DAY_DATA: Record<number, PlanDay[]> = {
    1: WEEK1_DAYS,
    2: WEEK2_DAYS,
    3: WEEK3_DAYS,
    4: WEEK4_DAYS,
    5: WEEK5_DAYS,
};

function phaseState(phase: WeekPhase): 'done' | 'current' | 'upcoming' {
    const weeks = SEASON_WEEKS.filter((w) => w.phase === phase);
    if (weeks.every((w) => w.status === 'done')) {
        return 'done';
    }
    if (weeks.some((w) => w.status === 'current')) {
        return 'current';
    }
    return 'upcoming';
}

function phaseAvgKm(phase: WeekPhase): number {
    const weeks = SEASON_WEEKS.filter((w) => w.phase === phase);
    return weeks.reduce((sum, w) => sum + w.km, 0) / weeks.length;
}

// Bar height traces the season's real volume arc — tallest at peak, shortest
// at taper — instead of 4 uniform boxes.
const PHASE_AVG_KM: Record<WeekPhase, number> = Object.fromEntries(
    PHASES.map((p) => [p.key, phaseAvgKm(p.key)]),
) as Record<WeekPhase, number>;
const PHASE_KM_RANGE = (() => {
    const values = Object.values(PHASE_AVG_KM);
    return { min: Math.min(...values), max: Math.max(...values) };
})();
function phaseBarHeightPct(phase: WeekPhase): number {
    const { min, max } = PHASE_KM_RANGE;
    const pct = (PHASE_AVG_KM[phase] - min) / (max - min);
    return 35 + pct * 65;
}

function computeAdherence(days: { score: number | null }[]): number {
    const scored = days.filter((d) => d.score != null);
    if (scored.length === 0) {
        return 0;
    }
    const avg =
        scored.reduce((sum, d) => sum + (d.score ?? 0), 0) / scored.length;
    return Math.round(Math.min(100, avg));
}

type SeasonActions = {
    weekDaysByWeek: Record<number, PlanDay[]>;
    onMoveSession: (weekNumber: number, fromWd: string, toWd: string) => void;
    onSkipSession: (weekNumber: number, wd: string) => void;
    onViewActivity: () => void;
};

function MiniSessionBar({
    segments,
}: Readonly<{ segments: SessionSegment[] }>) {
    if (segments.length === 0) {
        return null;
    }
    const total = segments.reduce((sum, seg) => sum + seg.minutes, 0);
    return (
        <div className="mt-1.5 flex h-1 gap-px" aria-hidden>
            {segments.map((seg, i) => (
                <div
                    key={i}
                    className="rounded-full"
                    style={{
                        width: `${(seg.minutes / total) * 100}%`,
                        backgroundColor: ZONE_COLOR[seg.zone],
                    }}
                />
            ))}
        </div>
    );
}

function SegmentLegendItem({
    label,
    zone,
    minutes,
    sub,
}: Readonly<{ label: string; zone: ZoneLevel; minutes: number; sub: string }>) {
    return (
        <div className="min-w-0 flex-1">
            <div className="flex items-center gap-1 font-mono text-[8px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase">
                <span
                    className="size-1.5 flex-none rounded-full"
                    style={{ backgroundColor: ZONE_COLOR[zone] }}
                    aria-hidden
                />
                {label}
            </div>
            <div className="mt-0.5 text-[10.5px] leading-[1.3] font-bold text-foreground">
                {minutes}min
            </div>
            <div className="text-[9.5px] leading-[1.3] text-foreground">
                {sub}
            </div>
        </div>
    );
}

function SessionBarGraph({
    segments,
}: Readonly<{ segments: SessionSegment[] }>) {
    const total = segments.reduce((sum, seg) => sum + seg.minutes, 0);
    const warmup = segments.find((s) => s.key === 'warmup');
    const cooldown = segments.find((s) => s.key === 'cooldown');
    const work = segments.find((s) => s.key === 'interval');
    const rest = segments.find((s) => s.key === 'recovery');
    const reps = segments.filter((s) => s.key === 'interval').length;
    const isRepeatSession = reps > 0;

    return (
        <div className="mt-2.5">
            <div className="flex h-8 items-end gap-0.5">
                {segments.map((seg, i) => (
                    <div
                        key={i}
                        className="rounded-t-[4px]"
                        style={{
                            width: `${(seg.minutes / total) * 100}%`,
                            height: `${ZONE_HEIGHT_PCT[seg.zone]}%`,
                            backgroundColor: ZONE_COLOR[seg.zone],
                        }}
                    />
                ))}
            </div>
            <div className="mt-1.5 flex gap-2.5">
                {isRepeatSession ? (
                    <>
                        {warmup && (
                            <SegmentLegendItem
                                label={SEGMENT_LABEL.warmup}
                                zone={warmup.zone}
                                minutes={warmup.minutes}
                                sub={warmup.sub}
                            />
                        )}
                        {work && (
                            <div className="min-w-0 flex-[2]">
                                <div className="flex items-center gap-1 font-mono text-[8px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase">
                                    <span
                                        className="size-1.5 flex-none rounded-full"
                                        style={{
                                            backgroundColor:
                                                ZONE_COLOR[work.zone],
                                        }}
                                        aria-hidden
                                    />
                                    {reps}× interval
                                </div>
                                <div className="mt-0.5 text-[10.5px] leading-[1.3] font-bold text-foreground">
                                    {work.minutes}min hard /{' '}
                                    {rest?.minutes ?? 0}min easy
                                </div>
                                <div className="text-[9.5px] leading-[1.3] text-foreground">
                                    {work.sub}
                                </div>
                            </div>
                        )}
                        {cooldown && (
                            <SegmentLegendItem
                                label={SEGMENT_LABEL.cooldown}
                                zone={cooldown.zone}
                                minutes={cooldown.minutes}
                                sub={cooldown.sub}
                            />
                        )}
                    </>
                ) : (
                    segments.map((seg, i) => (
                        <SegmentLegendItem
                            key={i}
                            label={SEGMENT_LABEL[seg.key]}
                            zone={seg.zone}
                            minutes={seg.minutes}
                            sub={seg.sub}
                        />
                    ))
                )}
            </div>
        </div>
    );
}

function WeekVolumeChart({
    days,
    isCurrent = false,
}: Readonly<{ days: PlanDay[]; isCurrent?: boolean }>) {
    const maxKm = Math.max(...days.map((d) => d.plannedKm), 1);
    const scoredDays = days.filter((d) => d.score != null);
    const weekCompliance = computeAdherence(days);
    const complianceTally = (
        ['done', 'partial', 'missed', 'overreached'] as const
    )
        .map(
            (status) =>
                [
                    status,
                    scoredDays.filter((d) => d.status === status).length,
                ] as const,
        )
        .filter(([, count]) => count > 0)
        .map(([status, count]) => `${count} ${status}`)
        .join(' · ');

    return (
        <div className="mb-3 rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <div className="font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.06em] text-foreground uppercase">
                        volume {isCurrent ? 'this week' : 'that week'}
                    </div>
                    <div className="mt-1 text-[10.5px] leading-[1.2] text-foreground">
                        {complianceTally}
                        {isCurrent && ' so far'}
                    </div>
                </div>
                <div className="font-mono text-xl leading-[1.2] font-extrabold text-icon-accent">
                    {weekCompliance}%
                </div>
            </div>
            <div className="mt-3 mb-1.5 flex items-center gap-3 text-[9px] leading-[1.2] text-foreground">
                <span className="flex items-center gap-1">
                    <span
                        className="size-2 rounded-[2px] border border-dashed border-border-strong"
                        aria-hidden
                    />
                    planned
                </span>
                <span className="flex items-center gap-1">
                    <span
                        className="size-2 rounded-[2px] bg-icon-accent"
                        aria-hidden
                    />
                    actual
                </span>
            </div>
            <div className="flex h-16 items-end gap-1.5">
                {days.map((d) => {
                    const plannedPct = (d.plannedKm / maxKm) * 100;
                    const actualPct =
                        d.actualKm != null ? (d.actualKm / maxKm) * 100 : null;
                    return (
                        <div
                            key={d.wd}
                            className="flex flex-1 flex-col items-center gap-1"
                        >
                            <div className="relative flex h-14 w-full items-end justify-center">
                                {d.plannedKm > 0 && (
                                    <div
                                        className="absolute w-full rounded-t-[4px] border border-dashed border-border-strong"
                                        style={{ height: `${plannedPct}%` }}
                                        aria-hidden
                                    />
                                )}
                                {actualPct != null && (
                                    <div
                                        className={cn(
                                            'absolute w-full rounded-t-[4px]',
                                            STATUS_BAR_FILL[d.status],
                                        )}
                                        style={{ height: `${actualPct}%` }}
                                    />
                                )}
                            </div>
                            <span className="font-mono text-[8px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase">
                                {d.wd}
                            </span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function SeasonHeaderCard({
    seasonAdherence,
}: Readonly<{ seasonAdherence: number }>) {
    const current = SEASON_WEEKS.find((w) => w.status === 'current');
    return (
        <div className="mb-3 rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <div className="font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.06em] text-foreground uppercase">
                        season · week {current?.week} of {SEASON_WEEKS.length}
                    </div>
                    <div className="mt-1 text-[11px] leading-[1.2] text-foreground">
                        {current?.phase} · 12 jun – 4 sep
                    </div>
                </div>
                <div className="flex-none text-right">
                    <div className="font-mono text-xl leading-[1.2] font-extrabold text-icon-accent">
                        {seasonAdherence}%
                    </div>
                    <div className="font-mono text-[8px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase">
                        adherence
                    </div>
                </div>
            </div>
            <div className="mt-3 flex items-end gap-1.5">
                {PHASES.map((p) => {
                    const state = phaseState(p.key);
                    const color = PHASE_COLOR[p.key];
                    return (
                        <div
                            key={p.key}
                            className="flex flex-1 flex-col items-center gap-1"
                        >
                            <div className="flex h-8 w-full items-end overflow-hidden rounded-[3px]">
                                <div
                                    className={cn(
                                        'w-full rounded-t-[3px]',
                                        state === 'upcoming' &&
                                            'border border-dashed',
                                    )}
                                    style={{
                                        height: `${phaseBarHeightPct(p.key)}%`,
                                        backgroundColor:
                                            state === 'upcoming'
                                                ? `color-mix(in oklab, ${color} 16%, transparent)`
                                                : color,
                                        borderColor:
                                            state === 'upcoming'
                                                ? color
                                                : undefined,
                                        boxShadow:
                                            state === 'current'
                                                ? `0 0 0 2px color-mix(in oklab, ${color} 35%, transparent)`
                                                : undefined,
                                    }}
                                />
                            </div>
                            <span
                                className={cn(
                                    'font-mono text-[8px] leading-[1.2] tracking-[.03em] text-foreground uppercase',
                                    state === 'current'
                                        ? 'font-extrabold'
                                        : 'font-medium',
                                )}
                            >
                                {p.label}
                            </span>
                        </div>
                    );
                })}
            </div>
            <div className="mt-3 flex items-center gap-1.25 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.05em] text-icon-accent uppercase">
                <Sparkles className="size-3" aria-hidden />
                temari&apos;s take
            </div>
            <p className="m-0 mt-1 font-serif text-[11.5px] leading-[1.5] text-foreground italic">
                base has been steady, no red flags — the kind of foundation
                build wants to launch from.
            </p>
        </div>
    );
}

function WeekDayRow({
    day,
    weekDays,
    onMove,
    onSkip,
    onViewActivity,
}: Readonly<{
    day: PlanDay;
    weekDays: PlanDay[];
    onMove: (toWd: string) => void;
    onSkip: () => void;
    onViewActivity: () => void;
}>) {
    const [picking, setPicking] = useState(false);
    const isRest = day.type === 'rest';
    const ranAnyway = isRest && day.activity != null;
    const Icon = TYPE_ICON[day.type];
    const maxZone = day.segments.reduce<number>(
        (max, s) => Math.max(max, s.zone),
        1,
    ) as ZoneLevel;
    let iconColor = ZONE_COLOR[maxZone];
    if (isRest) {
        iconColor = ranAnyway ? 'var(--leaf)' : 'var(--foreground)';
    }
    const todayIndex = ALL_WEEKDAYS.indexOf(
        (weekDays.find((d) => d.today)?.wd ??
            'mon') as (typeof ALL_WEEKDAYS)[number],
    );
    const isValidMoveTarget = (wd: string) =>
        wd !== day.wd &&
        ALL_WEEKDAYS.indexOf(wd as (typeof ALL_WEEKDAYS)[number]) >
            todayIndex &&
        weekDays.find((d) => d.wd === wd)?.type === 'rest';
    const hasMoveTargets = ALL_WEEKDAYS.some(isValidMoveTarget);
    const canMove =
        (day.status === 'upcoming' || day.status === 'skip') && hasMoveTargets;
    const canSkip = day.status !== 'skip';

    return (
        <Collapsible
            className={cn(
                'overflow-hidden rounded-[14px] border bg-card shadow-e1',
                day.today ? 'border-icon-accent' : 'border-border-strong',
            )}
        >
            <CollapsibleTrigger className="group flex w-full items-center gap-3 px-4 py-3 text-left">
                <div className="flex w-9 flex-none flex-col items-center gap-1">
                    <span className="font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase">
                        {day.wd}
                    </span>
                    <Icon
                        className="size-3.5"
                        style={{ color: iconColor }}
                        aria-hidden
                    />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="m-0 text-[12.5px] leading-[1.2] font-bold text-foreground capitalize">
                        {day.type}
                    </p>
                    {!isRest && (
                        <p className="mt-0.5 text-[11px] leading-[1.3] font-normal text-foreground">
                            {day.summary}
                        </p>
                    )}
                    {ranAnyway && (
                        <p className="mt-0.5 text-[11px] leading-[1.3] font-bold text-leaf">
                            ran anyway · {day.activity!.summary}
                        </p>
                    )}
                    <MiniSessionBar segments={day.segments} />
                    {!isRest && day.status !== 'upcoming' && (
                        <span
                            className={cn(
                                'mt-1 block font-mono text-[9px] leading-[1.2] font-extrabold uppercase',
                                STATUS_STYLE[day.status],
                            )}
                        >
                            {day.status}
                            {day.score != null && ` · ${day.score}%`}
                        </span>
                    )}
                </div>
                <ChevronDown
                    className="size-4 flex-none text-foreground transition-transform group-aria-expanded:rotate-180"
                    aria-hidden
                />
            </CollapsibleTrigger>
            <CollapsibleContent className="border-t border-border-strong px-4 py-3">
                <div className="mb-1 flex items-center gap-1.25 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.05em] text-icon-accent uppercase">
                    <Sparkles className="size-3" aria-hidden />
                    temari&apos;s take
                </div>
                <p className="m-0 font-serif text-[11.5px] leading-[1.5] text-foreground italic">
                    {day.detail}
                </p>
                {day.segments.length > 0 && (
                    <SessionBarGraph segments={day.segments} />
                )}
                {day.activity && (
                    <a
                        href="#"
                        onClick={(e) => {
                            e.preventDefault();
                            onViewActivity();
                        }}
                        className="mt-3 flex items-center gap-1.5 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.05em] text-icon-accent uppercase no-underline"
                    >
                        view activity · {day.activity.summary}
                        <ArrowRight className="size-3" aria-hidden />
                    </a>
                )}
                {!isRest && (
                    <div className="mt-3">
                        {picking ? (
                            <div className="grid grid-cols-7 gap-1.5">
                                {ALL_WEEKDAYS.map((wd) => {
                                    const valid = isValidMoveTarget(wd);
                                    return (
                                        <button
                                            key={wd}
                                            type="button"
                                            disabled={!valid}
                                            onClick={() => {
                                                onMove(wd);
                                                setPicking(false);
                                            }}
                                            className={cn(
                                                'flex aspect-square flex-col items-center justify-center rounded-xl border font-sans text-[10.5px] font-bold text-foreground uppercase',
                                                valid
                                                    ? 'border-border-strong'
                                                    : 'border-border-strong opacity-40',
                                            )}
                                        >
                                            {wd}
                                        </button>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="flex flex-wrap gap-3">
                                {canMove && (
                                    <button
                                        type="button"
                                        onClick={() => setPicking(true)}
                                        className="flex items-center gap-1.5 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.05em] text-icon-accent uppercase"
                                    >
                                        <ArrowRightLeft
                                            className="size-3"
                                            aria-hidden
                                        />
                                        move this session
                                    </button>
                                )}
                                {canSkip && (
                                    <button
                                        type="button"
                                        onClick={onSkip}
                                        className="flex items-center gap-1.5 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.05em] text-foreground uppercase"
                                    >
                                        <SkipForward
                                            className="size-3"
                                            aria-hidden
                                        />
                                        skip this session
                                    </button>
                                )}
                            </div>
                        )}
                    </div>
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}

function SeasonRailNode({ status }: Readonly<{ status: SeasonWeekStatus }>) {
    return (
        <span
            className={cn(
                'z-10 flex-none rounded-full',
                status === 'current' &&
                    'size-3 bg-icon-accent ring-4 ring-icon-accent/25',
                status === 'done' && 'size-2 bg-icon-accent',
                status === 'upcoming' &&
                    'size-2 border-2 border-border-strong bg-card',
            )}
            aria-hidden
        />
    );
}

function SeasonWeekRow({
    week,
    isLast,
    actions,
}: Readonly<{ week: SeasonWeek; isLast: boolean; actions: SeasonActions }>) {
    const weekDays = actions.weekDaysByWeek[week.week];
    const hasDetail = weekDays != null;
    const weekAdherence = hasDetail ? computeAdherence(weekDays) : null;

    return (
        <div className="flex gap-3">
            <div className="flex w-3 flex-none flex-col items-center">
                <SeasonRailNode status={week.status} />
                {!isLast && (
                    <span
                        className={cn(
                            'mt-1 w-0.5 flex-1 rounded-full',
                            week.status === 'upcoming'
                                ? 'bg-border-strong'
                                : 'bg-icon-accent',
                        )}
                        aria-hidden
                    />
                )}
            </div>
            <div className="min-w-0 flex-1 pb-3">
                {hasDetail ? (
                    <Collapsible
                        defaultOpen={week.status === 'current'}
                        className={cn(
                            'overflow-hidden rounded-[14px] border bg-card shadow-e1',
                            week.status === 'current'
                                ? 'border-icon-accent'
                                : 'border-border-strong',
                        )}
                    >
                        <CollapsibleTrigger className="group flex w-full items-center gap-3 px-4 py-3 text-left">
                            <span className="w-9 flex-none font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase">
                                wk {week.week}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="m-0 text-[12.5px] leading-[1.2] font-bold text-foreground">
                                    {week.range}
                                </p>
                                <span className="mt-0.5 block font-mono text-[9px] leading-[1.2] text-foreground uppercase">
                                    {week.km} km · {week.sessions} sessions
                                    {week.status === 'current' &&
                                        ' · this week'}
                                    {week.status !== 'current' &&
                                        ` · ${weekAdherence}%`}
                                </span>
                            </div>
                            <ChevronDown
                                className="size-4 flex-none text-foreground transition-transform group-aria-expanded:rotate-180"
                                aria-hidden
                            />
                        </CollapsibleTrigger>
                        <CollapsibleContent className="border-t border-border-strong px-4 py-3">
                            {week.status === 'done' && (
                                <div className="mb-1 flex items-center gap-1.25 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.05em] text-icon-accent uppercase">
                                    <Sparkles className="size-3" aria-hidden />
                                    temari&apos;s take
                                </div>
                            )}
                            <p
                                className={cn(
                                    'm-0 text-[11.5px] leading-[1.5] text-foreground',
                                    week.status === 'done' &&
                                        'font-serif italic',
                                )}
                            >
                                {week.focus}
                            </p>
                            <div className="mt-3 flex flex-col gap-2">
                                <WeekVolumeChart
                                    days={weekDays}
                                    isCurrent={week.status === 'current'}
                                />
                                {weekDays.map((day) => (
                                    <WeekDayRow
                                        key={day.wd}
                                        day={day}
                                        weekDays={weekDays}
                                        onMove={(toWd) =>
                                            actions.onMoveSession(
                                                week.week,
                                                day.wd,
                                                toWd,
                                            )
                                        }
                                        onSkip={() =>
                                            actions.onSkipSession(
                                                week.week,
                                                day.wd,
                                            )
                                        }
                                        onViewActivity={actions.onViewActivity}
                                    />
                                ))}
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                ) : (
                    <div className="rounded-[14px] border border-border-strong bg-card px-4 py-3 shadow-e1">
                        <div className="flex items-center gap-3">
                            <span className="w-9 flex-none font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase">
                                wk {week.week}
                            </span>
                            <p className="m-0 min-w-0 flex-1 text-[12.5px] leading-[1.2] font-bold text-foreground">
                                {week.range}
                            </p>
                        </div>
                        <p className="m-0 mt-2 text-[11px] leading-[1.5] text-foreground">
                            {week.focus}
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}

function WeekCluster({
    weeks,
    label,
    onExpand,
    isLast,
}: Readonly<{
    weeks: SeasonWeek[];
    label: string;
    onExpand: () => void;
    isLast: boolean;
}>) {
    const totalKm = weeks.reduce((sum, w) => sum + w.km, 0);
    const totalSessions = weeks.reduce((sum, w) => sum + w.sessions, 0);
    const allDone = weeks.every((w) => w.status === 'done');

    return (
        <div className="flex gap-3">
            <div className="flex w-3 flex-none flex-col items-center">
                <span
                    className="z-10 flex size-5 flex-none items-center justify-center rounded-full border-2 border-dashed border-border-strong bg-card text-foreground"
                    aria-hidden
                >
                    <Ellipsis className="size-3" />
                </span>
                {!isLast && (
                    <span
                        className={cn(
                            'mt-1 w-0.5 flex-1 rounded-full',
                            allDone ? 'bg-icon-accent' : 'bg-border-strong',
                        )}
                        aria-hidden
                    />
                )}
            </div>
            <button
                type="button"
                onClick={onExpand}
                className="mb-3 flex min-w-0 flex-1 items-center justify-between gap-3 rounded-[14px] border border-dashed border-border-strong bg-card px-4 py-3.5 text-left shadow-e1"
            >
                <div>
                    <p className="m-0 text-[12.5px] leading-[1.2] font-bold text-foreground">
                        {label}
                    </p>
                    <span className="mt-0.5 block font-mono text-[9px] leading-[1.2] text-foreground uppercase">
                        {totalKm} km · {totalSessions} sessions
                    </span>
                </div>
                <span className="flex-none font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.05em] text-icon-accent uppercase">
                    show
                </span>
            </button>
        </div>
    );
}

function SeasonTimeline({ actions }: Readonly<{ actions: SeasonActions }>) {
    const [pastOpen, setPastOpen] = useState(false);
    const [futureOpen, setFutureOpen] = useState(false);

    const currentWeek = SEASON_WEEKS.find((w) => w.status === 'current')!;
    const currentPhaseWeeks = SEASON_WEEKS.filter(
        (w) => w.phase === currentWeek.phase,
    );
    const pastInPhase = currentPhaseWeeks.filter(
        (w) => w.week < currentWeek.week,
    );
    const futureInPhase = currentPhaseWeeks.filter(
        (w) => w.week > currentWeek.week,
    );
    const laterPhaseWeeks = SEASON_WEEKS.filter(
        (w) => w.week > currentWeek.week && w.phase !== currentWeek.phase,
    );
    const currentPhaseLabel = PHASES.find(
        (p) => p.key === currentWeek.phase,
    )?.label;

    return (
        <div className="flex flex-col gap-4">
            <div>
                <div className="mb-2 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.08em] text-foreground uppercase">
                    {currentPhaseLabel} phase
                </div>
                <div className="flex flex-col">
                    {pastInPhase.length > 0 &&
                        (pastOpen ? (
                            pastInPhase.map((w) => (
                                <SeasonWeekRow
                                    key={w.week}
                                    week={w}
                                    isLast={false}
                                    actions={actions}
                                />
                            ))
                        ) : (
                            <WeekCluster
                                weeks={pastInPhase}
                                label={`${pastInPhase.length} weeks behind`}
                                onExpand={() => setPastOpen(true)}
                                isLast={false}
                            />
                        ))}
                    <SeasonWeekRow
                        week={currentWeek}
                        isLast={
                            futureInPhase.length === 0 &&
                            laterPhaseWeeks.length === 0
                        }
                        actions={actions}
                    />
                    {futureInPhase.map((w, i) => (
                        <SeasonWeekRow
                            key={w.week}
                            week={w}
                            isLast={
                                i === futureInPhase.length - 1 &&
                                laterPhaseWeeks.length === 0
                            }
                            actions={actions}
                        />
                    ))}
                </div>
            </div>

            {laterPhaseWeeks.length > 0 &&
                (futureOpen ? (
                    PHASES.filter((p) => p.key !== currentWeek.phase).map(
                        (phase) => {
                            const weeks = laterPhaseWeeks.filter(
                                (w) => w.phase === phase.key,
                            );
                            if (weeks.length === 0) {
                                return null;
                            }
                            return (
                                <div key={phase.key}>
                                    <div className="mb-2 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.08em] text-foreground uppercase">
                                        {phase.label} phase
                                    </div>
                                    <div className="flex flex-col">
                                        {weeks.map((w, i) => (
                                            <SeasonWeekRow
                                                key={w.week}
                                                week={w}
                                                isLast={i === weeks.length - 1}
                                                actions={actions}
                                            />
                                        ))}
                                    </div>
                                </div>
                            );
                        },
                    )
                ) : (
                    <WeekCluster
                        weeks={laterPhaseWeeks}
                        label={`${laterPhaseWeeks.length} weeks ahead`}
                        onExpand={() => setFutureOpen(true)}
                        isLast
                    />
                ))}
        </div>
    );
}

function NoPlanState() {
    return (
        <div className="mt-6 flex flex-col items-center gap-3 rounded-[14px] border border-border-strong bg-card px-6 py-10 text-center shadow-e1">
            <FaceIcon
                size={48}
                ring="var(--horizon)"
                fill="var(--card)"
                feature="var(--foreground)"
            />
            <p className="m-0 font-serif text-base leading-[1.2] font-semibold text-foreground italic">
                no plan yet.
            </p>
            <p className="m-0 max-w-[220px] text-xs leading-[1.5] text-foreground">
                hit regenerate and temari will lay out the weeks ahead.
            </p>
            <a
                href="#"
                className="mt-1 inline-flex items-center gap-1.5 rounded-full bg-muted px-3.5 py-2.25 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase no-underline"
            >
                <RefreshCw className="size-3" aria-hidden />
                regenerate
            </a>
        </div>
    );
}

export function PlanScreen({
    planState,
    raceState,
    aiReplanState,
    onTriggerAiReplan,
    weekDaysVariant = 'default',
    onViewActivity,
    onNavigateRace,
}: Readonly<{
    planState: 'has' | 'empty';
    raceState: 'unset' | 'set';
    aiReplanState: 'ready' | 'cooldown';
    onTriggerAiReplan: () => void;
    weekDaysVariant?: 'default' | 'showcase';
    onViewActivity: () => void;
    onNavigateRace: () => void;
}>) {
    const [weekDaysByWeek, setWeekDaysByWeek] = useState<
        Record<number, PlanDay[]>
    >({
        ...WEEKLY_DAY_DATA,
        6: weekDaysVariant === 'showcase' ? STATUS_SHOWCASE_DAYS : WEEK_DAYS,
    });

    const moveSession = (weekNumber: number, fromWd: string, toWd: string) => {
        setWeekDaysByWeek((prev) => {
            const days = prev[weekNumber];
            const fromIndex = days.findIndex((d) => d.wd === fromWd);
            const toIndex = days.findIndex((d) => d.wd === toWd);
            if (fromIndex === -1 || toIndex === -1 || fromIndex === toIndex) {
                return prev;
            }
            const next = [...days];
            next[fromIndex] = {
                ...days[toIndex],
                wd: days[fromIndex].wd,
                today: days[fromIndex].today,
            };
            next[toIndex] = {
                ...days[fromIndex],
                wd: days[toIndex].wd,
                today: days[toIndex].today,
            };
            return { ...prev, [weekNumber]: next };
        });
    };

    const skipSession = (weekNumber: number, wd: string) => {
        setWeekDaysByWeek((prev) => ({
            ...prev,
            [weekNumber]: prev[weekNumber].map((d) =>
                d.wd === wd
                    ? {
                          ...d,
                          status: 'skip' as DayStatus,
                          score: null,
                          actualKm: null,
                      }
                    : d,
            ),
        }));
    };

    const seasonAdherence = computeAdherence(
        Object.values(weekDaysByWeek).flat(),
    );

    const actions: SeasonActions = {
        weekDaysByWeek,
        onMoveSession: moveSession,
        onSkipSession: skipSession,
        onViewActivity,
    };

    return (
        <div className="px-4 pt-16 pb-22 @min-[900px]:mx-auto @min-[900px]:max-w-[760px] @min-[900px]:px-6 @min-[900px]:pt-6 @min-[900px]:pb-24">
            <div className="mt-3 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.12em] text-foreground uppercase">
                plan
            </div>
            <div className="mt-2 mb-3 flex items-start justify-between gap-3">
                <h1 className="m-0 font-serif text-[24px] leading-[1.15] font-semibold text-foreground italic">
                    the weeks
                    <br />
                    <em className="text-icon-accent">ahead.</em>
                </h1>
                {aiReplanState === 'cooldown' ? (
                    <AiReplanPill className="mt-1" />
                ) : (
                    <a
                        href="#"
                        onClick={(e) => {
                            e.preventDefault();
                            onTriggerAiReplan();
                        }}
                        className="mt-1 inline-flex flex-none items-center gap-1.25 rounded-full bg-muted px-2.75 py-1.5 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase no-underline outline-none focus-visible:ring-3 focus-visible:ring-ring/30"
                    >
                        <RefreshCw className="size-3" aria-hidden />
                        regenerate
                    </a>
                )}
            </div>
            <p className="m-0 mb-4 text-xs leading-[1.55] text-foreground">
                {raceState === 'set' ? (
                    <>
                        built around jakarta half marathon on 12 oct 2026, about
                        5 sessions a week.
                    </>
                ) : (
                    <>
                        no race set yet, so this cycles a steady
                        build-and-deload rhythm, about 5 sessions a week.
                    </>
                )}{' '}
                <a
                    href="#"
                    className="inline-flex items-center gap-0.5 font-bold text-icon-accent no-underline"
                >
                    {raceState === 'set' ? 'change your race' : 'set a race'}
                    <ArrowRight className="size-3" aria-hidden />
                </a>
            </p>

            <ScheduleRaceTabs
                active="schedule"
                onNavigate={(tab) => tab === 'race' && onNavigateRace()}
            />

            {planState === 'empty' ? (
                <NoPlanState />
            ) : (
                <>
                    <SeasonHeaderCard seasonAdherence={seasonAdherence} />
                    <SeasonTimeline actions={actions} />
                </>
            )}
        </div>
    );
}
