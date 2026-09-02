import type { AnalysisPayload, WeekPlanDay } from '@/types/inertia';

import {
    formatMonthDayId,
    mondayOf,
    parseNaiveLocalDate,
    sundayOf,
} from '@/lib/pace';

/** The Plan page's day rows read the same payload Home's week widget does. */
export type PlanDay = WeekPlanDay;

export interface PlanWeek {
    week_start: string;
    phase: string;
    type: 'history' | 'current' | 'lookahead';
    days: PlanDay[];
}

export interface SeasonSummaryWeek {
    week_start: string;
    phase: string;
    type: 'history' | 'current' | 'lookahead';
    planned_km: number;
    actual_km: number | null;
    sessions: number;
}

export interface PlanNarration {
    /** Keyed by date (Y-m-d) — only the current week's 7 days are ever requested. */
    days: Record<string, AnalysisPayload>;
    week: AnalysisPayload | null;
    season: AnalysisPayload | null;
}

export type PhaseState = 'done' | 'current' | 'upcoming';

export interface Phase {
    key: string;
    avgKm: number;
    state: PhaseState;
}

/**
 * One entry per distinct phase of the season, in season order, each with its
 * mean weekly volume and where the athlete stands in it. Built from the real
 * phase sequence rather than a fixed base/build/peak/taper four, so a
 * self-scaled season's repeating build/deload cycle renders honestly. Read by
 * Plan's season header and Profile's season card, which draw the same sequence
 * differently.
 */
export function phasesOf(weeks: SeasonSummaryWeek[]): Phase[] {
    const order: string[] = [];
    const totals = new Map<string, { km: number; count: number }>();
    const states = new Map<string, PhaseState>();

    for (const week of weeks) {
        if (!totals.has(week.phase)) {
            order.push(week.phase);
            totals.set(week.phase, { km: 0, count: 0 });
        }
        const total = totals.get(week.phase)!;
        total.km += week.planned_km;
        total.count += 1;

        const seen = states.get(week.phase);
        if (week.type === 'current') {
            states.set(week.phase, 'current');
        } else if (seen === undefined) {
            states.set(
                week.phase,
                week.type === 'history' ? 'done' : 'upcoming',
            );
        } else if (seen === 'done' && week.type === 'lookahead') {
            states.set(week.phase, 'upcoming');
        }
    }

    return order.map((key) => {
        const total = totals.get(key)!;
        return {
            key,
            avgKm: total.km / total.count,
            state: states.get(key) ?? 'upcoming',
        };
    });
}

export const PHASE_LABEL: Record<string, string> = {
    base: 'base',
    build: 'build',
    peak: 'peak',
    taper: 'taper',
    deload: 'deload',
};

export const SESSION_TYPE_LABEL: Record<string, string> = {
    easy: 'easy',
    long: 'long run',
    tempo: 'tempo',
    interval: 'interval',
    rest: 'rest',
};

export const SESSION_TYPE_ICON: Record<string, string> = {
    easy: 'mdi:feather',
    long: 'mdi:feather',
    tempo: 'mdi:fire',
    interval: 'mdi:fire',
    rest: 'mdi:bed',
};

export const STATUS_LABEL: Record<string, string> = {
    done: 'done',
    partial: 'partial',
    missed: 'missed',
    overreached: 'overreached',
    skip: 'skipped',
};

/** Label colour per compliance verdict. `planned` reads as neutral and is unlabelled. */
export const STATUS_TONE: Record<string, string> = {
    done: 'text-horizon-ink',
    partial: 'text-citrus-ink',
    missed: 'text-ember-ink',
    overreached: 'text-citrus-ink',
    skip: 'text-text-3',
};

/** The same verdict as a bar fill, for the week's planned-vs-actual chart. */
export const STATUS_BAR_FILL: Record<string, string> = {
    done: 'bg-horizon',
    partial: 'bg-citrus',
    missed: 'bg-ember',
    overreached: 'bg-citrus',
    skip: 'bg-ink-3',
};

/**
 * A run of days as one adherence figure: the mean of whatever compliance
 * scores exist, capped at 100 so a single big overreach can't read as a
 * season "at 140%". Days with no score (rest days, anything still upcoming)
 * are not counted rather than scored as zero.
 */
export function computeAdherence(
    days: ReadonlyArray<{ compliance_score: number | null }>,
): number | null {
    const scored = days.filter((d) => d.compliance_score != null);
    if (scored.length === 0) {
        return null;
    }
    const total = scored.reduce((sum, d) => sum + (d.compliance_score ?? 0), 0);
    return Math.round(Math.min(100, total / scored.length));
}

/** "jun 12–18" for a week start, collapsing the month when both ends share it. */
export function weekRangeLabel(weekStartIso: string): string {
    const monday = mondayOf(weekStartIso);
    const sunday = sundayOf(monday);
    if (monday.getMonth() === sunday.getMonth()) {
        const month = monday
            .toLocaleDateString('en-US', { month: 'short' })
            .toLowerCase();
        return `${month} ${monday.getDate()}–${sunday.getDate()}`;
    }
    return `${formatMonthDayId(monday)}–${formatMonthDayId(sunday)}`;
}

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** The weekday a Y-m-d falls on, as the day rows label it. */
export function weekdayLabel(iso: string): string {
    const date = parseNaiveLocalDate(iso);
    return date === null ? '' : WEEKDAYS[date.getDay()];
}
