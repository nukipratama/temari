import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    chunkIntoWeeks,
    dominantMoodOf,
    rarestRarityOf,
    useCalendar,
    type CalendarCell,
} from './useCalendar';

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
        rarity: null,
        activity_id: null,
        effort: null,
        runs: [],
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

    // The row sits beside that week's own ISO-week recap, so it counts the
    // whole Mon-Sun week rather than the slice inside the viewed month.
    it('sums km and run count across the whole week, padding days included', () => {
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
        expect(week.totalKm).toBe(15);
    });

    it("carries the week's rarest card and sunday", () => {
        const cells = cellsFor([
            { date: '2026-05-04', day: 4, rarity: 'rare' },
            { date: '2026-05-05', day: 5 },
            { date: '2026-05-06', day: 6, rarity: 'epic' },
            { date: '2026-05-07', day: 7 },
            { date: '2026-05-08', day: 8 },
            { date: '2026-05-09', day: 9 },
            { date: '2026-05-10', day: 10 },
        ]);
        const [week] = chunkIntoWeeks(cells);
        expect(week.rarity).toBe('epic');
        expect(week.weekEnding).toBe('2026-05-10');
    });

    it('returns an empty list for no cells', () => {
        expect(chunkIntoWeeks([])).toEqual([]);
    });

    it('leaves TRIMP unknown when the week ran but nothing scored', () => {
        const cells = cellsFor([
            { date: '2026-05-01', day: 1, distance_km: 5, trimp: null },
        ]);
        const [week] = chunkIntoWeeks(cells);
        expect(week.totalTrimp).toBeNull();
    });

    it('sums TRIMP across the whole week too', () => {
        const cells = cellsFor([
            { date: '2026-05-01', day: 1, trimp: 40 },
            {
                date: '2026-04-30',
                day: 30,
                trimp: 100,
                is_current_month: false,
            },
            { date: '2026-05-02', day: 2, trimp: 10 },
        ]);
        const [week] = chunkIntoWeeks(cells);
        expect(week.totalTrimp).toBe(150);
    });
});

describe('rarestRarityOf', () => {
    it('picks the best rarity in the set', () => {
        const cells = cellsFor([
            { date: '2026-05-01', day: 1, rarity: 'common' },
            { date: '2026-05-02', day: 2, rarity: 'legendary' },
            { date: '2026-05-03', day: 3, rarity: 'rare' },
        ]);
        expect(rarestRarityOf(cells)).toBe('legendary');
    });

    it('is null when nothing earned a card', () => {
        expect(
            rarestRarityOf(cellsFor([{ date: '2026-05-01', day: 1 }])),
        ).toBeNull();
    });
});

describe('useCalendar', () => {
    const CELLS = cellsFor([
        { date: '2026-05-01', day: 1, mood: 'blazing', distance_km: 5 },
        { date: '2026-05-02', day: 2 },
    ]);

    it('chunks cells into weeks and computes the dominant mood', () => {
        const { result } = renderHook(() =>
            useCalendar({
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
            useCalendar({
                cells: CELLS,
                month: '2026-05',
                todayMonth: '2026-05',
            }),
        );
        expect(current.current.isCurrentMonth).toBe(true);

        const { result: past } = renderHook(() =>
            useCalendar({
                cells: CELLS,
                month: '2026-04',
                todayMonth: '2026-05',
            }),
        );
        expect(past.current.isCurrentMonth).toBe(false);
    });
});
