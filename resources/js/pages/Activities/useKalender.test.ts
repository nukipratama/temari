import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { Mood } from '@/types/inertia';

import {
    chunkIntoWeeks,
    dominantMoodOf,
    isFilteredOut,
    useKalender,
    type CalendarCell,
} from './useKalender';

function cellsFor(
    rows: Array<Partial<CalendarCell> & Pick<CalendarCell, 'date' | 'day'>>,
): CalendarCell[] {
    return rows.map((r) => ({
        is_current_month: true,
        is_today: false,
        distance_km: null,
        pace_sec_per_km: null,
        avg_hr: null,
        trimp: null,
        mood: null,
        activity_id: null,
        ...r,
    }));
}

describe('dominantMoodOf', () => {
    it("picks the most frequent run mood among the month's own days", () => {
        const cells = cellsFor([
            { date: '2026-05-01', day: 1, mood: 'blazing' },
            { date: '2026-05-02', day: 2, mood: 'chill' },
            { date: '2026-05-03', day: 3, mood: 'chill' },
            { date: '2026-05-04', day: 4, mood: null },
        ]);
        expect(dominantMoodOf(cells)).toBe('chill');
    });

    it('breaks ties by MOOD_ORDER so the pick is deterministic', () => {
        const cells = cellsFor([
            { date: '2026-05-01', day: 1, mood: 'chill' },
            { date: '2026-05-02', day: 2, mood: 'blazing' },
        ]);
        expect(dominantMoodOf(cells)).toBe('blazing');
    });

    it('excludes padding days from adjacent months', () => {
        const cells = cellsFor([
            {
                date: '2026-04-30',
                day: 30,
                is_current_month: false,
                mood: 'chill',
            },
            {
                date: '2026-04-29',
                day: 29,
                is_current_month: false,
                mood: 'chill',
            },
            {
                date: '2026-05-01',
                day: 1,
                is_current_month: true,
                mood: 'blazing',
            },
        ]);
        expect(dominantMoodOf(cells)).toBe('blazing');
    });

    it('returns null when the month has no runs', () => {
        const cells = cellsFor([
            { date: '2026-05-01', day: 1 },
            { date: '2026-05-02', day: 2 },
        ]);
        expect(dominantMoodOf(cells)).toBeNull();
    });
});

describe('chunkIntoWeeks', () => {
    it('groups cells into 7-day rows, numbering weeks from 1', () => {
        const cells = cellsFor(
            Array.from({ length: 10 }, (_, i) => ({
                date: `2026-05-${String(i + 1).padStart(2, '0')}`,
                day: i + 1,
            })),
        );
        const weeks = chunkIntoWeeks(cells);
        expect(weeks).toHaveLength(2);
        expect(weeks[0]).toMatchObject({
            weekNumber: 1,
            weekStart: '2026-05-01',
        });
        expect(weeks[0].days).toHaveLength(7);
        expect(weeks[1]).toMatchObject({
            weekNumber: 2,
            weekStart: '2026-05-08',
        });
        expect(weeks[1].days).toHaveLength(3);
    });

    it('sums km and run count only for current-month days with a run', () => {
        const cells = cellsFor([
            {
                date: '2026-05-01',
                day: 1,
                distance_km: 5,
            },
            {
                date: '2026-04-30',
                day: 30,
                distance_km: 10,
                is_current_month: false,
            },
            { date: '2026-05-02', day: 2, distance_km: 0 },
            { date: '2026-05-03', day: 3 },
        ]);
        const [week] = chunkIntoWeeks(cells);
        expect(week.totalKm).toBe(5);
        expect(week.runCount).toBe(1);
    });

    it('returns an empty list for no cells', () => {
        expect(chunkIntoWeeks([])).toEqual([]);
    });
});

describe('isFilteredOut', () => {
    it('is never filtered out when the filter set is empty', () => {
        const cell = cellsFor([{ date: '2026-05-01', day: 1 }])[0];
        expect(isFilteredOut(cell, new Set())).toBe(false);
    });

    it('filters out a cell with no mood once a filter is active', () => {
        const cell = cellsFor([{ date: '2026-05-01', day: 1 }])[0];
        expect(isFilteredOut(cell, new Set<Mood>(['blazing']))).toBe(true);
    });

    it('keeps a cell whose mood is in the active filter set', () => {
        const cell = cellsFor([
            { date: '2026-05-01', day: 1, mood: 'blazing' },
        ])[0];
        expect(isFilteredOut(cell, new Set<Mood>(['blazing']))).toBe(false);
    });

    it('filters out a cell whose mood is not in the active filter set', () => {
        const cell = cellsFor([
            { date: '2026-05-01', day: 1, mood: 'chill' },
        ])[0];
        expect(isFilteredOut(cell, new Set<Mood>(['blazing']))).toBe(true);
    });
});

describe('useKalender', () => {
    const CELLS = cellsFor([
        { date: '2026-05-01', day: 1, mood: 'blazing', distance_km: 5 },
        { date: '2026-05-02', day: 2 },
    ]);

    it('chunks cells into weeks and computes the dominant mood', () => {
        const { result } = renderHook(() =>
            useKalender({
                cells: CELLS,
                month: '2026-05',
                todayMonth: '2026-05',
            }),
        );

        expect(result.current.weeks).toHaveLength(1);
        expect(result.current.dominantMood).toBe('blazing');
    });

    it('reports whether the viewed month is the current one', () => {
        const { result: current } = renderHook(() =>
            useKalender({
                cells: CELLS,
                month: '2026-05',
                todayMonth: '2026-05',
            }),
        );
        expect(current.current.isCurrentMonth).toBe(true);

        const { result: past } = renderHook(() =>
            useKalender({
                cells: CELLS,
                month: '2026-04',
                todayMonth: '2026-05',
            }),
        );
        expect(past.current.isCurrentMonth).toBe(false);
    });

    it('starts with an empty mood filter and toggles moods on/off', () => {
        const { result } = renderHook(() =>
            useKalender({
                cells: CELLS,
                month: '2026-05',
                todayMonth: '2026-05',
            }),
        );
        expect(result.current.moodFilter.size).toBe(0);

        act(() => result.current.toggleMood('blazing'));
        expect(result.current.moodFilter.has('blazing')).toBe(true);

        act(() => result.current.toggleMood('blazing'));
        expect(result.current.moodFilter.has('blazing')).toBe(false);
    });

    it('resets the mood filter back to empty', () => {
        const { result } = renderHook(() =>
            useKalender({
                cells: CELLS,
                month: '2026-05',
                todayMonth: '2026-05',
            }),
        );

        act(() => result.current.toggleMood('blazing'));
        expect(result.current.moodFilter.size).toBe(1);

        act(() => result.current.resetFilter());
        expect(result.current.moodFilter.size).toBe(0);
    });
});
