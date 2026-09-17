import { Bed, Feather, Flag, Flame } from 'lucide-react';

import type { IconComponent } from '@/components/ui/Icon';
import type {
    AnalysisPayload,
    PlanDayClamp,
    PlanDayEasedFrom,
    PlanDayPaceEasedFrom,
    WeekPlanDay,
} from '@/types/inertia';

import {
    formatMonthDayId,
    formatPace,
    isoDateLocal,
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
    /** `general` before the race block opens, `block` inside it; a self-scaled week is always `general`. */
    zone: 'general' | 'block';
    type: 'history' | 'current' | 'lookahead';
    planned_km: number;
    /** The current week's target before a recorded ease took km off it. */
    eased_from_km?: number | null;
    actual_km: number | null;
    sessions: number;
}

export interface PlanNarration {
    /** Keyed by date (Y-m-d) — only the current week's 7 days are ever requested. */
    days: Record<string, AnalysisPayload>;
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
    race: 'race day',
};

export const SESSION_TYPE_ICON: Record<string, IconComponent> = {
    easy: Feather,
    long: Feather,
    tempo: Flame,
    interval: Flame,
    rest: Bed,
    race: Flag,
};

export const STATUS_LABEL: Record<string, string> = {
    done: 'done',
    partial: 'partial',
    missed: 'missed',
    overreached: 'overreached',
    skip: 'skipped',
};

/** What each verdict means: a day is graded on its distance and on the session's intent. */
export const STATUS_MEANING: Record<string, string> = {
    done: 'ran the distance and the session it asked for',
    partial:
        'short on the distance, or the run missed what the session was for',
    missed: 'no run, or too little to count',
    overreached: 'well past the distance, or ran harder than the session asked',
    skip: 'excused, not graded',
};

/** Label colour per compliance verdict. `planned` reads as neutral and is unlabelled. */
export const STATUS_TONE: Record<string, string> = {
    done: 'text-horizon-ink',
    partial: 'text-citrus-ink',
    missed: 'text-ember-ink',
    overreached: 'text-horizon-ink',
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

/**
 * Whether the goal race falls inside the week starting `weekStartIso`. Read off
 * the race date the page already holds rather than a per-week flag: the season
 * summary covers weeks far past the periodizer's day-row horizon, which is
 * exactly where a race sits.
 */
export function isRaceWeek(
    weekStartIso: string,
    raceDateIso: string | null,
): boolean {
    if (raceDateIso === null) {
        return false;
    }

    const monday = mondayOf(weekStartIso);

    return (
        raceDateIso >= isoDateLocal(monday) &&
        raceDateIso <= isoDateLocal(sundayOf(monday))
    );
}

/** The session an eased day replaced, with its distance only when that moved. */
export function easedFromLabel(easedFrom: PlanDayEasedFrom): string {
    const session =
        SESSION_TYPE_LABEL[easedFrom.session_type] ?? easedFrom.session_type;

    return easedFrom.distance_km === null
        ? `eased from ${session}`
        : `eased from ${easedFrom.distance_km} km ${session}`;
}

/**
 * The eased session on one line. The distance is dropped when the clamp left
 * it alone — an intensity-only step-down (Tempo/Interval to Easy keeps the
 * same core km) otherwise prints the identical figure twice, which reads as a
 * rendering bug rather than as "same distance, easier pace".
 */
export function clampSummary(clamp: PlanDayClamp, plannedKm: number): string {
    const parts = [
        SESSION_TYPE_LABEL[clamp.session_type] ?? clamp.session_type,
    ];
    if (clamp.distance_km !== plannedKm) {
        parts.push(`${clamp.distance_km} km`);
    }
    if (clamp.pace_sec_per_km !== null) {
        parts.push(`${formatPace(clamp.pace_sec_per_km)}/km`);
    }
    return parts.join(' · ');
}

/**
 * A day the plan has already judged states both facts it recorded: what it
 * asked for, and what was run. Every other day shows the ask alone, sized
 * against the athlete's fitness today.
 */
export function kmLabel(day: PlanDay): string {
    if (day.prescribed_km == null) {
        return `${day.distance_km} km`;
    }

    return `${day.prescribed_km} km asked · ${day.actual_km ?? 0} km run`;
}

/**
 * The current week's live volume redistribution can shrink an ungraded day's
 * `distance_km` below what it was originally sized at (`asked_km`) as the
 * athlete banks km elsewhere in the week. Returns that original figure only
 * when it has actually moved, so the day's header can say why its number
 * doesn't match what Home or its own narration says. A graded day (`prescribed_km`
 * set) already has a settled answer to this via {@see kmLabel}.
 */
export function volumeAdjustedFrom(day: PlanDay): number | null {
    if (day.prescribed_km != null || day.session_type === 'rest') {
        return null;
    }

    return Math.abs(day.asked_km - day.distance_km) > 0.05
        ? day.asked_km
        : null;
}

/** The core set's pace target, which is the one pace an unrun day is read at. */
export function paceLabel(day: PlanDay): string | null {
    const core = day.segments.find(
        (s) => s.key === 'main' || s.key === 'interval',
    );

    return core?.pace_sec_per_km == null
        ? null
        : `${formatPace(core.pace_sec_per_km)}/km`;
}

/**
 * The pace-only step-down as one line: "6:00 → 6:15/km". The day's own
 * segments already carry the slower (eased) pace, so this only supplies the
 * pace it replaced — the arrow is the whole story, no session type or
 * distance to name since neither moved.
 */
export function paceEaseLabel(
    paceEasedFrom: PlanDayPaceEasedFrom,
    day: PlanDay,
): string | null {
    const current = paceLabel(day);
    if (paceEasedFrom.pace_sec_per_km == null || current === null) {
        return null;
    }

    return `${formatPace(paceEasedFrom.pace_sec_per_km)} → ${current}`;
}

/** Whether a day's status counts toward the week's "showed up" total — mirrors `PlannedSessionStatus::isCredited()`. */
export function isCreditedStatus(status: PlanDay['status']): boolean {
    return (
        status === 'done' || status === 'partial' || status === 'overreached'
    );
}

/**
 * Once a day has a credited run, its prescribed pace can no longer stand
 * alone next to the run's own distance — it would read as the run's pace,
 * which is the bug this replaced. Each half is labelled and shown only when
 * it has a real number behind it: `target` drops out with no prescribed
 * pace, `ran` drops out when the credited runs carry no moving time to
 * compute one from.
 */
export function creditedPaceLabel(day: PlanDay): string | null {
    const target = paceLabel(day);
    const parts = [
        target === null ? null : `target ${target}`,
        day.ran_pace_sec_per_km == null
            ? null
            : `ran ${formatPace(day.ran_pace_sec_per_km)}/km`,
    ].filter((part): part is string => part !== null);

    return parts.length === 0 ? null : parts.join(' · ');
}

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** The weekday a Y-m-d falls on, as the day rows label it. */
export function weekdayLabel(iso: string): string {
    const date = parseNaiveLocalDate(iso);
    return date === null ? '' : WEEKDAYS[date.getDay()];
}
