import { Bed, Feather, Flag, Flame } from 'lucide-react';

import type { IconComponent } from '@/components/ui/Icon';
import type {
    AnalysisPayload,
    PlanDayClamp,
    PlanDayEasedFrom,
    PlanDayPaceEasedFrom,
    PlanSessionSegment,
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
        const key = phaseGroupKey(week);
        if (!totals.has(key)) {
            order.push(key);
            totals.set(key, { km: 0, count: 0 });
        }
        const total = totals.get(key)!;
        total.km += week.planned_km;
        total.count += 1;

        const seen = states.get(key);
        if (week.type === 'current') {
            states.set(key, 'current');
        } else if (seen === undefined) {
            states.set(key, week.type === 'history' ? 'done' : 'upcoming');
        } else if (seen === 'done' && week.type === 'lookahead') {
            states.set(key, 'upcoming');
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

/** The shared group key every week before the race block opens displays under, regardless of the self-scaled cycle's own build/deload phase. */
export const GENERAL_PHASE_KEY = 'general';

/**
 * The identity a week displays as: its own phase inside the race block, or
 * one shared "maintain" identity for every `zone: general` week — the
 * self-scaled cycle alternates Build/Deload before the block opens, and
 * naming that would read as noise rather than as the season's real arc. Used
 * everywhere a week's phase would otherwise be shown or grouped: the season
 * header, the phase legend ({@see phasesOf}), and the timeline's phase runs.
 */
export function phaseGroupKey(week: SeasonSummaryWeek): string {
    return week.zone === 'general' ? GENERAL_PHASE_KEY : week.phase;
}

export const PHASE_LABEL: Record<string, string> = {
    base: 'base',
    build: 'build',
    peak: 'peak',
    taper: 'taper',
    deload: 'deload',
    [GENERAL_PHASE_KEY]: 'maintain',
};

/**
 * The Monday-to-Sunday span the general zone covers — the first general
 * week's start through the last general week's end — or `null` when the
 * season has no general weeks (already inside the block, or goal-less).
 * Reading this off `weeks` rather than the season's own `starts_at`/`ends_at`
 * keeps the header from claiming "maintain" spans dates that are really the
 * race block's.
 */
export function generalZoneSpan(
    weeks: SeasonSummaryWeek[],
): { start: string; end: string } | null {
    const generalWeeks = weeks.filter((week) => week.zone === 'general');
    if (generalWeeks.length === 0) {
        return null;
    }

    const last = generalWeeks[generalWeeks.length - 1];

    return {
        start: generalWeeks[0].week_start,
        end: isoDateLocal(sundayOf(mondayOf(last.week_start))),
    };
}

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
 * scores exist, capping each day at 100 before averaging so a single big
 * overreach can't paper over a missed day. Days with no score (rest days,
 * anything still upcoming) are not counted rather than scored as zero.
 */
export function computeAdherence(
    days: ReadonlyArray<{ compliance_score: number | null }>,
): number | null {
    const scored = days.filter((d) => d.compliance_score != null);
    if (scored.length === 0) {
        return null;
    }
    const total = scored.reduce(
        (sum, d) => sum + Math.min(100, d.compliance_score ?? 0),
        0,
    );
    return Math.round(total / scored.length);
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

/** Which way a plan number moved — up when it increased, down when it decreased. */
export type DeltaDirection = 'up' | 'down';

/** @see DeltaDirection */
export function deltaDirection(from: number, to: number): DeltaDirection {
    return to >= from ? 'up' : 'down';
}

/**
 * A changed session, read as a delta: the type when it moved, the distance
 * when it moved. `typeFrom`/`distanceFrom` are null when that half held — an
 * intensity-only ease names the distance alone, unchanged type omitted.
 */
export interface SessionChangeDelta {
    typeFrom: string | null;
    typeTo: string;
    distanceFrom: string | null;
    distanceTo: string;
    direction: DeltaDirection;
}

export function easedFromDelta(
    easedFrom: PlanDayEasedFrom,
    day: PlanDay,
): SessionChangeDelta {
    const fromType =
        SESSION_TYPE_LABEL[easedFrom.session_type] ?? easedFrom.session_type;
    const toType = SESSION_TYPE_LABEL[day.session_type] ?? day.session_type;

    return {
        typeFrom: fromType === toType ? null : fromType,
        typeTo: toType,
        distanceFrom:
            easedFrom.distance_km === null ? null : `${easedFrom.distance_km}`,
        distanceTo: `${day.distance_km}`,
        direction:
            easedFrom.distance_km === null
                ? 'down'
                : deltaDirection(easedFrom.distance_km, day.distance_km),
    };
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
 * The current week's live volume redistribution can shrink an ungraded day's
 * `distance_km` below what it was originally sized at (`asked_km`) as the
 * athlete banks km elsewhere in the week. Returns that original figure only
 * when it has actually moved, so the day's header can say why its number
 * doesn't match what Home or its own narration says. A graded day
 * (`prescribed_km` set) already has a settled answer to this via
 * {@see judgedDayResult}.
 */
export function volumeAdjustedFrom(day: PlanDay): number | null {
    if (day.prescribed_km != null || day.session_type === 'rest') {
        return null;
    }

    return Math.round(Math.abs(day.asked_km - day.distance_km) * 10) >= 5
        ? day.asked_km
        : null;
}

/** What a session is for and how it should feel, in one line. */
export function sessionPurpose(day: PlanDay): string | null {
    const hasWork = day.segments.some((s) => s.zone > 'Z2');
    switch (day.session_type) {
        case 'long':
            return hasWork
                ? 'long run with goal-pace work. rehearses race day on tired legs.'
                : 'time on feet. builds the engine the race runs on. chatty pace the whole way.';
        case 'tempo':
            return hasWork
                ? 'comfortably hard. teaches you to hold a pace without tipping over.'
                : null;
        case 'interval':
            return hasWork
                ? 'short, hard reps. lifts your ceiling so race pace feels roomier.'
                : null;
        case 'easy':
            return "easy means easy. slow enough to talk, that's the whole point.";
        case 'rest':
            return 'rest day. this is where the training actually lands.';
        case 'race':
            return 'race day. trust the work, and start slower than you want to.';
        default:
            return null;
    }
}

function segmentLabel(segment: PlanSessionSegment): string {
    const minutes =
        segment.minutes == null ? null : `${Math.round(segment.minutes)} min`;
    const pace =
        segment.pace_sec_per_km == null
            ? segment.pace_label
            : `${formatPace(segment.pace_sec_per_km)}/km`;

    switch (segment.key) {
        case 'warmup':
            return `${minutes ?? 'easy'} warm-up`;
        case 'recovery':
            return `${minutes ?? 'easy'} jog`;
        default:
            if (segment.zone <= 'Z2') {
                return segment.km == null
                    ? 'easy to finish'
                    : `${segment.km} km easy`;
            }
            return `${minutes ?? segment.pace_label} at ${pace}`;
    }
}

function sameWork(a: PlanSessionSegment, b: PlanSessionSegment): boolean {
    return (
        a.key === b.key &&
        a.zone === b.zone &&
        Math.round(a.minutes ?? -1) === Math.round(b.minutes ?? -1)
    );
}

/**
 * The session's shape in one line: warm-up, the work, the way home. Repeated
 * reps collapse to `4 × 3 min at 4:20/km, 2 min jog between`. Null for a
 * single-block day, whose headline already says everything.
 */
export function sessionShape(segments: PlanSessionSegment[]): string | null {
    if (segments.length < 2) {
        return null;
    }

    const parts: string[] = [];
    for (let i = 0; i < segments.length; i++) {
        const work = segments[i];
        let reps = 1;
        while (
            work.zone > 'Z2' &&
            segments[i + 1]?.key === 'recovery' &&
            segments[i + 2] !== undefined &&
            sameWork(work, segments[i + 2])
        ) {
            reps++;
            i += 2;
        }
        if (reps > 1) {
            parts.push(
                `${reps} × ${segmentLabel(work)}, ${segmentLabel(segments[i - 1])} between`,
            );
        } else {
            parts.push(segmentLabel(work));
        }
    }

    return parts.join(' → ');
}

const PRESCRIPTION_WHY: Record<string, string> = {
    'conservative start with sparse comparable evidence':
        'starting modest. not enough sessions like this yet to size it up.',
    'progressed after the latest comparable session was hit':
        'a step up. you hit the last one.',
    'stepped down after the latest comparable session was too hard':
        'a notch down. the last one ran too hot.',
    'held after the latest comparable session':
        'same dose as last time. nail it before it grows.',
    'bounded by this week’s easy-time reserve':
        'capped so the week keeps enough easy running around it.',
    'easy because the week has no safe room for meaningful quality':
        'kept easy. the week has no safe room for real quality.',
    'easy because the weekly hard-day budget is already full':
        'kept easy. the week already has its hard days.',
    'easy to preserve recovery between hard days':
        'kept easy. too close to another hard day.',
};

/** The engine's reason for today's dose, only when it actually shaped the day. */
export function prescriptionWhy(reason: string | null): string | null {
    return reason === null ? null : (PRESCRIPTION_WHY[reason] ?? null);
}

/** The core segment's own pace, in seconds/km — the number every pace figure
 *  on a day (the target, a pace-ease delta) reads from. Null with no VDOT
 *  estimate to size one. */
function corePaceSecPerKm(day: PlanDay): number | null {
    const core = day.segments.find(
        (s) => s.key === 'main' || s.key === 'interval',
    );

    return core?.pace_sec_per_km ?? null;
}

/** The core set's pace target, which is the one pace an unrun day is read at. */
export function paceLabel(day: PlanDay): string | null {
    const sec = corePaceSecPerKm(day);
    return sec == null ? null : `${formatPace(sec)}/km`;
}

/**
 * The pace-only step-down as a delta: the original pace and the day's own
 * (already eased, effective) one — the day's own segments already carry the
 * eased pace, so this only supplies the pace it replaced. No direction: a
 * bigger pace number is an easier day, not a "worse" one, so this never
 * carries an up/down arrow — render it with `DeltaPair`'s `neutral` direction.
 */
export interface PaceEaseDelta {
    from: string;
    to: string;
}

export function paceEaseDelta(
    paceEasedFrom: PlanDayPaceEasedFrom,
    day: PlanDay,
): PaceEaseDelta | null {
    const toSec = corePaceSecPerKm(day);
    if (paceEasedFrom.pace_sec_per_km == null || toSec == null) {
        return null;
    }

    return {
        from: formatPace(paceEasedFrom.pace_sec_per_km),
        to: `${formatPace(toSec)}/km`,
    };
}

/**
 * A day the plan has judged, paired by side rather than by figure: what it
 * asked for (distance and its effective pace together) and what was run
 * (distance and the pace over the credited runs). The asked pace is the
 * effective target — the eased pace on a pace-eased day, since `segments`
 * already carries it (#932/#965); the ran pace is the pace over the runs
 * `SessionMatcher` credits (#962). Null on a day still ahead, which shows the
 * ask alone instead.
 */
export interface JudgedDayResult {
    askedKm: number;
    askedPace: string | null;
    ranKm: number;
    ranPace: string | null;
}

export function judgedDayResult(day: PlanDay): JudgedDayResult | null {
    if (day.prescribed_km == null) {
        return null;
    }

    return {
        askedKm: day.prescribed_km,
        askedPace: paceLabel(day),
        ranKm: day.actual_km ?? 0,
        ranPace:
            day.ran_pace_sec_per_km == null
                ? null
                : `${formatPace(day.ran_pace_sec_per_km)}/km`,
    };
}

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** The weekday a Y-m-d falls on, as the day rows label it. */
export function weekdayLabel(iso: string): string {
    const date = parseNaiveLocalDate(iso);
    return date === null ? '' : WEEKDAYS[date.getDay()];
}
