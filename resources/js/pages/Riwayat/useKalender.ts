import { useCallback, useMemo, useState } from 'react';

import type { Mood } from '@/types/inertia';

import { MOOD_ORDER } from '@/lib/mood';

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
}

/**
 * The mood Temari wears on the month's recap: the most frequent run mood among
 * the viewed month's own days (padding days from adjacent months are excluded).
 * Ties resolve by {@link MOOD_ORDER} so the pick is deterministic. Null when the
 * month has no runs, letting the card fall back to a neutral pose.
 */
export function dominantMoodOf(
    cells: ReadonlyArray<CalendarCell>,
): Mood | null {
    const counts = new Map<Mood, number>();
    for (const cell of cells) {
        if (!cell.is_current_month || cell.mood === null) {
            continue;
        }
        counts.set(cell.mood, (counts.get(cell.mood) ?? 0) + 1);
    }

    let dominant: Mood | null = null;
    let topCount = 0;
    for (const mood of MOOD_ORDER) {
        const count = counts.get(mood) ?? 0;
        if (count > topCount) {
            topCount = count;
            dominant = mood;
        }
    }

    return dominant;
}

export function chunkIntoWeeks(cells: ReadonlyArray<CalendarCell>): WeekRow[] {
    const weeks: WeekRow[] = [];
    for (let i = 0; i < cells.length; i += 7) {
        const days = cells.slice(i, i + 7);
        if (days.length === 0) continue;
        let totalKm = 0;
        let runCount = 0;
        for (const day of days) {
            if (
                day.distance_km !== null &&
                day.distance_km > 0 &&
                day.is_current_month
            ) {
                totalKm += day.distance_km;
                runCount += 1;
            }
        }
        weeks.push({
            weekStart: days[0].date,
            weekNumber: weeks.length + 1,
            days,
            totalKm,
            runCount,
        });
    }
    return weeks;
}

/**
 * Precomputed in the parent so toggling the mood filter passes a stable boolean
 * to each memoized cell, letting React skip cells whose dimmed state is unchanged.
 */
export function isFilteredOut(
    cell: CalendarCell,
    moodFilter: ReadonlySet<Mood>,
): boolean {
    return (
        moodFilter.size > 0 &&
        (cell.mood === null || !moodFilter.has(cell.mood))
    );
}

interface KalenderDataProps {
    cells: ReadonlyArray<CalendarCell>;
    month: string;
    todayMonth: string;
}

export function useKalender({ cells, month, todayMonth }: KalenderDataProps) {
    const weeks = useMemo<WeekRow[]>(() => chunkIntoWeeks(cells), [cells]);
    const dominantMood = useMemo(() => dominantMoodOf(cells), [cells]);
    const isCurrentMonth = month === todayMonth;
    const [moodFilter, setMoodFilter] = useState<ReadonlySet<Mood>>(
        () => new Set(),
    );
    const toggleMood = useCallback((mood: Mood) => {
        setMoodFilter((prev) => {
            const next = new Set(prev);
            if (next.has(mood)) next.delete(mood);
            else next.add(mood);
            return next;
        });
    }, []);
    const resetFilter = useCallback(() => setMoodFilter(new Set()), []);

    return {
        weeks,
        dominantMood,
        isCurrentMonth,
        moodFilter,
        toggleMood,
        resetFilter,
    };
}
