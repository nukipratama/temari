// PROTOTYPE — throwaway, calendar redesign variants
import type { CalendarCell } from '@/pages/Activities/useCalendar';
import type { Effort, Mood } from '@/types/inertia';

export type Variant = 'A' | 'B' | 'C';
export const VARIANTS: ReadonlyArray<Variant> = ['A', 'B', 'C'];
export const VARIANT_LABEL: Record<Variant, string> = {
    A: 'A · spec tiles',
    B: 'B · big distance',
    C: 'C · bar chart',
};

export interface CellRun {
    activity_id: number;
    name: string | null;
    distance_km: number | null;
    pace_sec_per_km: number | null;
    effort: Effort;
    mood: Mood | null;
}

export type ProtoCell = CalendarCell & { runs: CellRun[]; faked?: boolean };

export function readVariant(url: string): Variant {
    const value = new URLSearchParams(url.split('?')[1] ?? '').get('variant');
    return value === 'B' || value === 'C' ? value : 'A';
}

export function calendarUrl(month: string, variant: Variant): string {
    return `/history?view=calendar&month=${month}&variant=${variant}`;
}

export const hasRun = (cell: CalendarCell): boolean =>
    cell.distance_km !== null && cell.distance_km > 0;

export const runCount = (cell: ProtoCell): number =>
    hasRun(cell) ? Math.max(1, cell.runs?.length ?? 0) : 0;

export const kmLabel = (km: number): string => km.toFixed(1);

/**
 * The demo seed has no two-run day, so the latest run day of the visible month
 * gets a fake second run to exercise the multi-run sheet.
 */
export function withFakeMultiRun(
    cells: ReadonlyArray<CalendarCell>,
): ProtoCell[] {
    const out: ProtoCell[] = cells.map((cell) => ({
        ...cell,
        runs: (cell as ProtoCell).runs ?? [],
    }));
    const monthRuns = out.filter((c) => c.is_current_month && hasRun(c));
    if (monthRuns.some((c) => c.runs.length > 1) || monthRuns.length < 2) {
        return out;
    }
    const target = monthRuns[monthRuns.length - 1];
    const donor = monthRuns[monthRuns.length - 2];
    const fake: CellRun = {
        activity_id: donor.runs[0]?.activity_id ?? donor.activity_id ?? 0,
        name: 'evening shakeout (fake)',
        distance_km: 3.2,
        pace_sec_per_km: 372,
        effort: 'easy',
        mood: 'chill',
    };
    target.runs = [...target.runs, fake];
    target.distance_km = (target.distance_km ?? 0) + 3.2;
    target.activity_id = null;
    target.faked = true;
    return out;
}

export interface Consistency {
    runs: number;
    km: number;
    ranDays: number;
    daysInMonth: number;
    longestStreak: number;
}

export function consistencyOf(cells: ReadonlyArray<ProtoCell>): Consistency {
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

export const EFFORT_WORD: Record<Effort, string> = {
    easy: 'easy',
    steady: 'steady',
    hard: 'hard',
    rest: 'planned rest',
    unknown: 'unscored',
};

export const EFFORT_FILL: Record<Effort, string> = {
    easy: 'bg-leaf',
    steady: 'bg-citrus',
    hard: 'bg-ember',
    rest: 'border-t-[3px] border-dashed border-border-strong',
    unknown: 'bg-border-strong',
};

export function describeDay(cell: ProtoCell): string {
    const n = runCount(cell);
    if (n === 0) {
        return `${cell.date}: ${cell.effort === 'rest' ? 'planned rest' : 'no run'}`;
    }
    const effort = cell.effort ? `, ${EFFORT_WORD[cell.effort]}` : '';
    return `${cell.date}${cell.is_today ? ' (today)' : ''}: ${kmLabel(cell.distance_km ?? 0)} km${effort}${n > 1 ? `, ${n} runs` : ''}`;
}
