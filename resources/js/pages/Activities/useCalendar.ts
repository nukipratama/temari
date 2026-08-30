import { useMemo } from 'react';

import type { Mood } from '@/types/inertia';

import { dominantMood as pickDominantMood } from '@/lib/mood';

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
    activity_id: number | null;
}

export interface WeekRow {
    weekStart: string;
    weekNumber: number;
    days: CalendarCell[];
    totalKm: number;
    runCount: number;
    /** Null when the week ran but nothing scored (unknown, not zero). */
    totalTrimp: number | null;
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

export function chunkIntoWeeks(cells: ReadonlyArray<CalendarCell>): WeekRow[] {
    const weeks: WeekRow[] = [];
    for (let i = 0; i < cells.length; i += 7) {
        const days = cells.slice(i, i + 7);
        if (days.length === 0) continue;
        let totalKm = 0;
        let runCount = 0;
        let totalTrimp: number | null = null;
        for (const day of days) {
            if (!day.is_current_month) continue;
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
            weekNumber: weeks.length + 1,
            days,
            totalKm,
            runCount,
            totalTrimp,
        });
    }
    return weeks;
}

/** Sums each week's already-computed totals across the whole displayed month. */
export function monthTotalsOf(weeks: ReadonlyArray<WeekRow>): MonthTotals {
    let runs = 0;
    let km = 0;
    let trimp: number | null = null;
    for (const week of weeks) {
        runs += week.runCount;
        km += week.totalKm;
        if (week.totalTrimp !== null) {
            trimp = (trimp ?? 0) + week.totalTrimp;
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
    const monthTotals = useMemo(() => monthTotalsOf(weeks), [weeks]);
    const isCurrentMonth = month === todayMonth;

    return {
        weeks,
        dominantMood,
        monthTotals,
        isCurrentMonth,
    };
}
