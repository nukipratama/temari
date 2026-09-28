import type { Effort } from '@/types/inertia';

import { formatNaiveMonthDayId } from '@/lib/pace';

import type { CalendarCell } from './useCalendar';

/** A day "ran" when it carries a positive summed distance. */
export const hasRun = (cell: CalendarCell): boolean =>
    cell.distance_km !== null && cell.distance_km > 0;

/**
 * Number of runs behind a cell: the `runs` breakdown when present, floored at
 * 1 for a run day whose breakdown is thin. Takes the max of the two signals
 * so a real logged run with no (or zero) distance — summed `distance_km`
 * misses it, `hasRun` alone would read the day as run-less — still counts,
 * still links, and isn't dropped from the month's stats.
 */
export const runCount = (cell: CalendarCell): number =>
    Math.max(hasRun(cell) ? 1 : 0, cell.runs.length);

export const kmLabel = (km: number): string => km.toFixed(1);

export const EFFORT_WORD: Record<Effort, string> = {
    easy: 'easy',
    steady: 'steady',
    hard: 'hard',
    rest: 'planned rest',
    unknown: 'unscored',
};

/** Bar fill per effort, keyed the same as {@link EFFORT_WORD}. Rest draws as a dashed outline, never a fill. */
export const EFFORT_FILL: Record<Effort, string> = {
    easy: 'bg-leaf',
    steady: 'bg-citrus',
    hard: 'bg-ember',
    rest: 'border-t-[3px] border-dashed border-border-strong',
    unknown: 'bg-border-strong',
};

const MIN_BAR_HEIGHT_PCT = 8;

/**
 * A day's bar height as a percentage of the grid's tallest day, with a
 * minimum so a short run still draws a visible sliver.
 */
export function barHeightPct(km: number, gridMaxKm: number): number {
    if (gridMaxKm <= 0) {
        return MIN_BAR_HEIGHT_PCT;
    }
    return Math.max(MIN_BAR_HEIGHT_PCT, Math.round((km / gridMaxKm) * 100));
}

/** The tallest single day's distance across the whole rendered grid (padding days included), never zero. */
export function gridMaxKm(cells: ReadonlyArray<CalendarCell>): number {
    return Math.max(1, ...cells.map((cell) => cell.distance_km ?? 0));
}

export interface BarSegment {
    activityId: number;
    effort: Effort;
    heightPct: number;
}

/**
 * One bar segment per run, stacked bottom-up in the order `cell.runs`
 * arrives — the backend orders by start time, so the earliest run lands at
 * the bottom. Each segment's height uses the same per-grid scale as a
 * single-run day (own distance, own effort colour), so a multi-run day
 * reads as a taller bar instead of collapsing to its hardest run's colour.
 * Falls back to one segment from the day's aggregate when the breakdown is
 * thin (a legacy summary-only day with no per-run detail).
 */
export function barSegments(cell: CalendarCell, gridMax: number): BarSegment[] {
    if (cell.runs.length > 0) {
        return cell.runs.map((run) => ({
            activityId: run.activity_id,
            effort: run.effort,
            heightPct: barHeightPct(run.distance_km ?? 0, gridMax),
        }));
    }
    if (cell.effort === null || cell.effort === 'rest') {
        return [];
    }
    return [
        {
            activityId: cell.activity_id ?? 0,
            effort: cell.effort,
            heightPct: barHeightPct(cell.distance_km ?? 0, gridMax),
        },
    ];
}

export interface Consistency {
    runs: number;
    km: number;
    ranDays: number;
    daysInMonth: number;
    longestStreak: number;
}

/**
 * The header's consistency line math, scoped to the viewed month's own days
 * (padding days from adjacent months excluded). The streak counts only
 * consecutive run days within the visible month — a gap at either month edge
 * breaks it, it doesn't carry over from the padding days.
 */
export function consistencyOf(cells: ReadonlyArray<CalendarCell>): Consistency {
    let runs = 0;
    let km = 0;
    let ranDays = 0;
    let daysInMonth = 0;
    let streak = 0;
    let longestStreak = 0;

    for (const cell of cells) {
        if (!cell.is_current_month) continue;
        daysInMonth += 1;
        if (hasRun(cell)) {
            runs += runCount(cell);
            km += cell.distance_km ?? 0;
            ranDays += 1;
            streak += 1;
            longestStreak = Math.max(longestStreak, streak);
        } else {
            streak = 0;
        }
    }

    return { runs, km, ranDays, daysInMonth, longestStreak };
}

/**
 * A day cell's accessible name, since the bar draws only numbers and color.
 * A multi-run day names the run count ahead of the day total (e.g. "Sep 24,
 * 2 runs, 13.5 km") since the bar itself is now a stack of per-run segments,
 * not one hardest-effort colour.
 */
export function describeDay(cell: CalendarCell): string {
    const n = runCount(cell);
    const date = formatNaiveMonthDayId(cell.date);
    const today = cell.is_today ? ' (today)' : '';
    if (n === 0) {
        return `${date}${today}: ${cell.effort === 'rest' ? 'planned rest' : 'no run'}`;
    }
    if (n > 1) {
        return `${date}${today}, ${n} runs, ${kmLabel(cell.distance_km ?? 0)} km`;
    }
    const effort = cell.effort ? `, ${EFFORT_WORD[cell.effort]}` : '';
    return `${date}${today}: ${kmLabel(cell.distance_km ?? 0)} km${effort}`;
}
