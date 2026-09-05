import { useMemo } from 'react';

import type { Mood, Rarity } from '@/types/inertia';

import { dominantMood as pickDominantMood } from '@/lib/mood';
import { RARITY_ORDER } from '@/lib/runcard';

export interface CalendarCell {
    date: string;
    day: number;
    is_current_month: boolean;
    is_today: boolean;
    distance_km: number | null;
    pace_sec_per_km: number | null;
    avg_hr: number | null;
    trimp: number | null;
    mood: Mood | null;
    /** The day's rarest earned card, when one was earned. */
    rarity: Rarity | null;
    activity_id: number | null;
}

export interface WeekRow {
    weekStart: string;
    /** ISO date of the week's Sunday — matches WeeklySnapshot.week_ending. */
    weekEnding: string;
    weekNumber: number;
    days: CalendarCell[];
    totalKm: number;
    runCount: number;
    /** Null when the week ran but nothing scored (unknown, not zero). */
    totalTrimp: number | null;
    /** The week's own mood, across all seven days. */
    mood: Mood | null;
    /** The week's rarest earned card, across all seven days. */
    rarity: Rarity | null;
}

export interface MonthTotals {
    runs: number;
    km: number;
    /** Null when the month ran but nothing scored. */
    trimp: number | null;
}

/**
 * The mood Temari wears on the month's recap: the most frequent run mood among
 * the viewed month's own days (padding days from adjacent months are
 * excluded). Null when the month has no runs, letting the card fall back to a
 * neutral pose.
 */
export function dominantMoodOf(
    cells: ReadonlyArray<CalendarCell>,
): Mood | null {
    return pickDominantMood(
        cells.filter((cell) => cell.is_current_month).map((cell) => cell.mood),
    );
}

/**
 * The week's rarest earned card, or null when it earned none.
 */
export function rarestRarityOf(
    cells: ReadonlyArray<CalendarCell>,
): Rarity | null {
    let best: Rarity | null = null;
    for (const cell of cells) {
        if (
            cell.rarity !== null &&
            (best === null ||
                RARITY_ORDER.indexOf(cell.rarity) > RARITY_ORDER.indexOf(best))
        ) {
            best = cell.rarity;
        }
    }
    return best;
}

/**
 * Week rows total the whole Mon-Sun week, padding days included: a row sits
 * beside that week's recap narration, which is itself ISO-week-grained, so
 * scoping the row to the viewed month would have it contradict the sentence
 * next to it. The month meta is scoped separately, in {@link monthTotalsOf}.
 */
export function chunkIntoWeeks(cells: ReadonlyArray<CalendarCell>): WeekRow[] {
    const weeks: WeekRow[] = [];
    for (let i = 0; i < cells.length; i += 7) {
        const days = cells.slice(i, i + 7);
        if (days.length === 0) continue;
        let totalKm = 0;
        let runCount = 0;
        let totalTrimp: number | null = null;
        for (const day of days) {
            if (day.distance_km !== null && day.distance_km > 0) {
                totalKm += day.distance_km;
                runCount += 1;
            }
            if (day.trimp !== null) {
                totalTrimp = (totalTrimp ?? 0) + day.trimp;
            }
        }
        weeks.push({
            weekStart: days[0].date,
            weekEnding: days[days.length - 1].date,
            weekNumber: weeks.length + 1,
            days,
            totalKm,
            runCount,
            totalTrimp,
            mood: pickDominantMood(days.map((day) => day.mood)),
            rarity: rarestRarityOf(days),
        });
    }
    return weeks;
}

/** The viewed month's own totals: padding days from adjacent months excluded. */
export function monthTotalsOf(cells: ReadonlyArray<CalendarCell>): MonthTotals {
    let runs = 0;
    let km = 0;
    let trimp: number | null = null;
    for (const cell of cells) {
        if (!cell.is_current_month) continue;
        if (cell.distance_km !== null && cell.distance_km > 0) {
            km += cell.distance_km;
            runs += 1;
        }
        if (cell.trimp !== null) {
            trimp = (trimp ?? 0) + cell.trimp;
        }
    }
    return { runs, km, trimp };
}

interface CalendarDataProps {
    cells: ReadonlyArray<CalendarCell>;
    month: string;
    todayMonth: string;
}

export function useCalendar({ cells, month, todayMonth }: CalendarDataProps) {
    const weeks = useMemo<WeekRow[]>(() => chunkIntoWeeks(cells), [cells]);
    const dominantMood = useMemo(() => dominantMoodOf(cells), [cells]);
    const monthTotals = useMemo(() => monthTotalsOf(cells), [cells]);
    const isCurrentMonth = month === todayMonth;

    return {
        weeks,
        dominantMood,
        monthTotals,
        isCurrentMonth,
    };
}
