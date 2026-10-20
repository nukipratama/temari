import {
    ArrowUp,
    Bed,
    Check,
    CircleDashed,
    Feather,
    Flag,
    Flame,
    Minus,
    X,
} from 'lucide-react';

import type { IconComponent } from '@/components/ui/Icon';
import type {
    PlanDayEasedFrom,
    PaceRange,
    PlanDayPaceEasedFrom,
    PlanSessionSegment,
    WeekPlanDay,
} from '@/types/inertia';

import {
    formatPace,
    formatPaceWithRange,
    isoDateLocal,
    mondayOf,
    parseNaiveLocalDate,
    sundayOf,
} from '@/lib/pace';
import { seasonPhaseKey } from '@/lib/planPhases';

/** The Plan page's day rows read the same payload Home's week widget does. */
export type PlanDay = WeekPlanDay;

export interface PlanWeek {
    week_start: string;
    phase: string;
    type: 'history' | 'current' | 'lookahead';
    days: EditablePlanDay[];
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
    /** A week behind's mean capped compliance score; null for this week, weeks ahead and unscored weeks. */
    adherence_pct: number | null;
}

/** The first later week whose phase identity differs from the current week's, and how many weeks away it is. */
export function nextPhaseOf(
    weeks: SeasonSummaryWeek[],
): { key: string; inWeeks: number } | null {
    const currentIndex = weeks.findIndex((week) => week.type === 'current');
    if (currentIndex === -1) {
        return null;
    }

    const currentKey = seasonPhaseKey(weeks[currentIndex]);
    for (let i = currentIndex + 1; i < weeks.length; i++) {
        const key = seasonPhaseKey(weeks[i]);
        if (key !== currentKey) {
            return { key, inWeeks: i - currentIndex };
        }
    }

    return null;
}

const MS_PER_WEEK = 7 * 24 * 60 * 60 * 1000;

/** Whole weeks from the week starting `weekStartIso` to the week the race date falls in; 0 in race week. */
export function weeksToRace(weekStartIso: string, raceDateIso: string): number {
    return Math.round(
        (mondayOf(raceDateIso).getTime() - mondayOf(weekStartIso).getTime()) /
            MS_PER_WEEK,
    );
}

/** "1 week", "8 weeks". */
export function weeksLabel(count: number): string {
    return `${count} ${count === 1 ? 'week' : 'weeks'}`;
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
    hot: 'ran hot',
    skip: 'skipped',
};

/** What each verdict means: a day is graded on its distance and on the session's intent. */
export const STATUS_MEANING: Record<string, string> = {
    done: 'ran the distance and the session it asked for',
    partial:
        'short on the distance, or the run missed what the session was for',
    missed: 'no run, or too little to count',
    overreached: 'well past the distance',
    hot: 'ran harder than the session asked',
    skip: 'excused, not graded',
};

/**
 * Day status as a glyph, never a colour (design-system/temari/MASTER.md §
 * Plan week strip): the day tile, day cell and headline status label all read
 * their icon from here rather than each keeping their own status→icon table.
 * `planned`/`hot` carry no entry — an upcoming day shows no glyph, and `hot`
 * is `overreached` read for intent rather than a status of its own.
 */
export const STATUS_GLYPH: Partial<Record<string, IconComponent>> = {
    done: Check,
    overreached: ArrowUp,
    partial: CircleDashed,
    missed: X,
    skip: Minus,
};

/** An overreached day graded on intent, not distance: it ran too hard, not too far. */
export function ranHot(day: PlanDay): boolean {
    return day.status === 'overreached' && day.ran_hot;
}

/**
 * The status glyph a day tile/cell shows, per {@see STATUS_GLYPH}. A rest day
 * kept as rest shows no glyph at all — its dashed effort edge already says
 * "rest" — and a day excused before the scorer has run is still `planned`
 * server-side, so `skipped` is checked ahead of `status`.
 */
export function dayStatusGlyph(day: PlanDay): IconComponent | null {
    if (day.session_type === 'rest' && !day.ran_anyway) {
        return null;
    }
    if (day.skipped) {
        return STATUS_GLYPH.skip ?? null;
    }
    return STATUS_GLYPH[day.status] ?? null;
}

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

const TALLIED_STATUSES = ['done', 'partial', 'missed', 'overreached'] as const;

/** How a week's graded days have gone so far, e.g. `1 done · 2 missed`; empty before any day is graded. */
export function complianceTally(
    days: ReadonlyArray<{ status: string }>,
): string {
    return TALLIED_STATUSES.map((status) => ({
        status,
        count: days.filter((d) => d.status === status).length,
    }))
        .filter(({ count }) => count > 0)
        .map(({ status, count }) => `${count} ${status}`)
        .join(' · ');
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

function sessionHasWork(day: Pick<PlanDay, 'segments'>): boolean {
    return day.segments.some((s) => s.zone > 'Z2');
}

export const GOAL_PACE_LABEL = 'goal pace';

export const STEPPING_STONE_LABEL = 'stepping-stone pace';

const GOAL_PACE_RACE: Record<NonNullable<PlanDay['goal_pace']>, string> = {
    '5k': '5K',
    '10k': '10K',
    half: 'half marathon',
    marathon: 'marathon',
};

export const TIME_TRIAL_LABEL = 'time trial';

export const FAST_FINISH_LABEL = 'long run with a fast finish';

function goalPaceLabel(
    day: Pick<PlanDay, 'goal_pace' | 'stepping_stone'>,
): string | undefined {
    if (day.goal_pace === null) {
        return undefined;
    }

    return day.stepping_stone ? STEPPING_STONE_LABEL : GOAL_PACE_LABEL;
}

const RACE_LONG_LABEL: Record<
    NonNullable<PlanDay['race_long']>['pace_source'],
    string
> = {
    goal: 'long run with goal-pace work',
    stepping_stone: 'long run with stepping-stone work',
    supported: 'long run with marathon-pace work',
};

function paceSourceLabel(
    pace: PlanDay['fast_finish'] | PlanDay['race_long'],
): string | undefined {
    switch (pace?.pace_source) {
        case undefined:
            return undefined;
        case 'goal':
            return GOAL_PACE_LABEL;
        case 'stepping_stone':
            return STEPPING_STONE_LABEL;
        case 'supported':
            return `supported ${GOAL_PACE_RACE[pace.pace_kind]} pace`;
    }
}

/** The pace a fast-finish long run's fast block runs at, if the day is one. */
export function fastFinishPaceLabel(
    day: Pick<PlanDay, 'fast_finish'>,
): string | undefined {
    return paceSourceLabel(day.fast_finish);
}

/** The pace a marathon race long's work runs at, if the day is one. */
export function raceLongPaceLabel(
    day: Pick<PlanDay, 'race_long'>,
): string | undefined {
    return paceSourceLabel(day.race_long);
}

/** The session's name: a time trial, goal-pace work, a fast-finish long run or a marathon race long by its own, every other day by its type. */
export function sessionLabel(
    day: Pick<
        PlanDay,
        | 'session_type'
        | 'goal_pace'
        | 'stepping_stone'
        | 'time_trial'
        | 'fast_finish'
        | 'race_long'
    >,
): string {
    if (day.time_trial !== null) {
        return TIME_TRIAL_LABEL;
    }
    if (day.fast_finish !== null) {
        return FAST_FINISH_LABEL;
    }
    if (day.race_long !== null) {
        return RACE_LONG_LABEL[day.race_long.pace_source];
    }

    return (
        goalPaceLabel(day) ??
        SESSION_TYPE_LABEL[day.session_type] ??
        day.session_type
    );
}

/** The label a session's hard segments carry in place of their pace band, if any. */
export function workLabel(
    day: Pick<PlanDay, 'goal_pace' | 'stepping_stone' | 'time_trial'>,
): string | undefined {
    if (day.time_trial !== null) {
        return TIME_TRIAL_LABEL;
    }

    return goalPaceLabel(day);
}

export const TIME_TRIAL_HINT =
    'warm up first like you would before a race, then record the trial as its own run if you can.';

/** The one practical line a time trial day, or a day run under a heart-rate cap, carries. */
export function sessionHint(
    day: Pick<
        PlanDay,
        'time_trial' | 'hr_cap_bpm' | 'session_type' | 'segments'
    >,
): string | null {
    if (day.time_trial !== null) {
        return TIME_TRIAL_HINT;
    }
    if (day.hr_cap_bpm === null) {
        return null;
    }
    if (sessionHasWork(day)) {
        return `keep the easy running around it under ${day.hr_cap_bpm} bpm.`;
    }

    const pace = paceLabel(day);
    if (day.session_type === 'long') {
        return pace === null
            ? "the pace may slow late as you tire, and that's fine."
            : `about ${pace} early on. the pace may slow late as you tire, and that's fine.`;
    }

    return pace === null ? null : `about ${pace}.`;
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

const DOSE_WHY: Record<string, string> = {
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
};

const KEPT_EASY_WHY: Record<string, string> = {
    'easy because the week has no safe room for meaningful quality':
        'kept easy. the week has no safe room for real quality.',
    'easy because the weekly hard-day budget is already full':
        'kept easy. the week already has its hard days.',
    'easy to preserve recovery between hard days':
        'kept easy. too close to another hard day.',
    'easy because the outing cannot safely fit the minimum quality structure':
        "kept easy. the day's too short to fit a proper quality set.",
};

/** The engine's reason for today's dose, only when the day's segments still bear it out. */
export function prescriptionWhy(day: PlanDay): string | null {
    if (day.prescription_reason === null || day.eased_from) {
        return null;
    }

    return (
        (sessionHasWork(day) ? DOSE_WHY : KEPT_EASY_WHY)[
            day.prescription_reason
        ] ?? null
    );
}

const FALL_OFF_TILT_WHY: Record<string, string> = {
    'endurance:tempo':
        'threshold this week: your pace fades over longer distances.',
    'endurance:long':
        'a little longer this week: your pace fades over longer distances.',
    'speed:interval':
        'intervals this week: you hold pace well over longer distances, so speed has the most room to grow.',
};

/** Why the athlete's own fall-off shaped this session, only on a session it moved. */
export function fallOffTiltWhy(day: PlanDay): string | null {
    if (day.fall_off_tilt === null) {
        return null;
    }

    return (
        FALL_OFF_TILT_WHY[`${day.fall_off_tilt}:${day.session_type}`] ?? null
    );
}

function coreSegment(
    day: Pick<PlanDay, 'segments'>,
): PlanSessionSegment | undefined {
    return day.segments.find((s) => s.key === 'main' || s.key === 'interval');
}

/** The core segment's own pace, in seconds/km — the number every pace figure
 *  on a day (the target, a pace-ease delta) reads from. Null with no VDOT
 *  estimate to size one. */
function corePaceSecPerKm(day: Pick<PlanDay, 'segments'>): number | null {
    return coreSegment(day)?.pace_sec_per_km ?? null;
}

function corePaceWithRange(day: Pick<PlanDay, 'segments'>): string | null {
    const core = coreSegment(day);
    return core?.pace_sec_per_km == null
        ? null
        : formatPaceWithRange(core.pace_sec_per_km, core.pace_range);
}

/** The core set's pace target, which is the one pace an unrun day is read at. */
export function paceLabel(day: Pick<PlanDay, 'segments'>): string | null {
    const sec = corePaceSecPerKm(day);
    return sec == null ? null : `${formatPace(sec)}/km`;
}

interface WorkUnit {
    minutes: number | null;
    km: number | null;
    paceSecPerKm: number | null;
    paceRange: PaceRange | null;
}

function oneDecimal(value: number): number {
    return Math.round(value * 10) / 10;
}

function joined(first: WorkUnit, second: WorkUnit): WorkUnit {
    if (
        first.minutes === null ||
        second.minutes === null ||
        first.paceSecPerKm === null ||
        second.paceSecPerKm === null
    ) {
        return first;
    }
    const minutes = first.minutes + second.minutes;
    const km =
        (first.minutes * 60) / first.paceSecPerKm +
        (second.minutes * 60) / second.paceSecPerKm;

    return {
        minutes,
        km: oneDecimal(km),
        paceSecPerKm: Math.round((minutes * 60) / km),
        paceRange:
            first.paceRange === null || second.paceRange === null
                ? null
                : {
                      fast_sec_per_km: Math.min(
                          first.paceRange.fast_sec_per_km,
                          second.paceRange.fast_sec_per_km,
                      ),
                      slow_sec_per_km: Math.max(
                          first.paceRange.slow_sec_per_km,
                          second.paceRange.slow_sec_per_km,
                      ),
                  },
    };
}

/** The work segments, where back-to-back ones with no recovery between them
 *  are one rep or block at their combined pace, as the judge reads them. */
function workUnits(segments: PlanSessionSegment[]): WorkUnit[] {
    const units: WorkUnit[] = [];
    let joinsPrevious = false;
    for (const segment of segments) {
        const isWork =
            (segment.key === 'main' || segment.key === 'interval') &&
            segment.pace_label !== 'easy';
        if (!isWork) {
            joinsPrevious = false;
            continue;
        }
        const unit = {
            minutes: segment.minutes,
            km: segment.km,
            paceSecPerKm: segment.pace_sec_per_km,
            paceRange: segment.pace_range ?? null,
        };
        const previous = joinsPrevious ? units.pop() : undefined;
        units.push(previous === undefined ? unit : joined(previous, unit));
        joinsPrevious = true;
    }

    return units;
}

/** What a day is run to, in the terms it is judged in: the blocks or reps of
 *  a tempo or interval day, the block and the cap on a long run that has
 *  one, the heart-rate cap on an easy or long run, and the pace of a time
 *  trial or race. */
export function targetLabel(day: PlanDay): string | null {
    if (day.time_trial !== null || day.session_type === 'race') {
        return corePaceWithRange(day);
    }

    const units = workUnits(day.segments);
    if (units.length === 0) {
        return day.hr_cap_bpm !== null
            ? `under ${day.hr_cap_bpm} bpm`
            : corePaceWithRange(day);
    }

    const pace =
        units[0].paceSecPerKm === null
            ? ''
            : ` at ${formatPaceWithRange(units[0].paceSecPerKm, units[0].paceRange)}`;
    if (day.session_type === 'long') {
        const km = oneDecimal(
            units.reduce((sum, unit) => sum + (unit.km ?? 0), 0),
        );
        const minutes = oneDecimal(
            units.reduce((sum, unit) => sum + (unit.minutes ?? 0), 0),
        );
        const length = km > 0 ? `${km} km` : `${minutes} min`;
        const block = `${day.fast_finish !== null ? 'last ' : ''}${length}${pace}`;

        return day.hr_cap_bpm !== null
            ? `${block} · easy part under ${day.hr_cap_bpm} bpm`
            : block;
    }

    const minutes = units.flatMap((unit) =>
        unit.minutes === null ? [] : [unit.minutes],
    );
    if (minutes.length < units.length) {
        return corePaceWithRange(day);
    }
    const total = minutes.reduce((sum, value) => sum + value, 0);
    const work =
        minutes.length === 1
            ? `${Math.round(total)} min`
            : Math.max(...minutes) - Math.min(...minutes) <= 1
              ? `${minutes.length} × ${Math.round(total / minutes.length)} min`
              : `${minutes.map((value) => Math.round(value)).join(' + ')} min`;

    return `${work}${pace}`;
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
 * asked for and what was run, each in the same terms. The ask is
 * {@see targetLabel}, read off the effective segments, so a pace-eased day
 * shows its eased pace (#932/#965); the answer is `ran_label`, read off the
 * judge's evidence, falling back to the pace over the runs `SessionMatcher`
 * credits (#962). Null on a day still ahead, which shows the ask alone
 * instead.
 */
export interface JudgedDayResult {
    askedKm: number;
    asked: string | null;
    ranKm: number;
    ran: string | null;
}

export function judgedDayResult(day: PlanDay): JudgedDayResult | null {
    if (day.prescribed_km == null) {
        return null;
    }

    return {
        askedKm: day.prescribed_km,
        asked: targetLabel(day),
        ranKm: day.actual_km ?? 0,
        ran:
            day.ran_label ??
            (day.ran_pace_sec_per_km == null
                ? null
                : `${formatPace(day.ran_pace_sec_per_km)}/km`),
    };
}

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** The weekday a Y-m-d falls on, as the day rows label it. */
export function weekdayLabel(iso: string): string {
    const date = parseNaiveLocalDate(iso);
    return date === null ? '' : WEEKDAYS[date.getDay()];
}

/** A Plan page day row with the edits `SessionEditRules` allows on it. */
export type EditablePlanDay = PlanDay & {
    actions: { move: boolean; skip: boolean; restore: boolean };
    /** The days, Y-m-d, a move may land on. */
    move_targets: string[];
    /** Set on a day a make-up emptied: the day, Y-m-d, its session moved to. */
    made_up_on: string | null;
};
