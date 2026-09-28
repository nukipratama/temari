import { describe, expect, it } from 'vitest';

import type { CalendarCell, CalendarCellRun } from './useCalendar';

import {
    barHeightPct,
    barSegments,
    consistencyOf,
    describeDay,
    EFFORT_FILL,
    EFFORT_WORD,
    gridMaxKm,
    hasRun,
    kmLabel,
    runCount,
} from './calendarBars';

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

function run(overrides: Partial<CalendarCellRun> = {}): CalendarCellRun {
    return {
        activity_id: 1,
        name: 'run',
        distance_km: 5,
        pace_sec_per_km: 300,
        effort: 'easy',
        mood: null,
        ...overrides,
    };
}

describe('hasRun / runCount / kmLabel', () => {
    it('is false for a null or zero distance', () => {
        expect(hasRun(cellsFor([{ date: 'd', day: 1 }])[0])).toBe(false);
        expect(
            hasRun(cellsFor([{ date: 'd', day: 1, distance_km: 0 }])[0]),
        ).toBe(false);
    });

    it('counts runs from the breakdown, falling back to 1 for a thin one', () => {
        const [thin] = cellsFor([{ date: 'd', day: 1, distance_km: 5 }]);
        expect(runCount(thin)).toBe(1);

        const [multi] = cellsFor([
            { date: 'd', day: 1, distance_km: 8, runs: [run(), run()] },
        ]);
        expect(runCount(multi)).toBe(2);
    });

    it('formats km to one decimal', () => {
        expect(kmLabel(10.449)).toBe('10.4');
        expect(kmLabel(0)).toBe('0.0');
    });
});

describe('effort tables', () => {
    it('carry a word and a fill for every effort', () => {
        for (const effort of [
            'easy',
            'steady',
            'hard',
            'rest',
            'unknown',
        ] as const) {
            expect(EFFORT_WORD[effort]).toBeTruthy();
            expect(EFFORT_FILL[effort]).toBeTruthy();
        }
    });
});

describe('bar scaling', () => {
    it('scales relative to the grid max, capping the tallest day at 100%', () => {
        expect(barHeightPct(10, 10)).toBe(100);
        expect(barHeightPct(5, 10)).toBe(50);
    });

    it('enforces a minimum visible height for a short run', () => {
        expect(barHeightPct(0.1, 20)).toBe(8);
    });

    it('never divides by zero when the grid has no runs', () => {
        expect(barHeightPct(0, 0)).toBe(8);
    });

    it('derives the grid max from the tallest single day, never zero', () => {
        const cells = cellsFor([
            { date: 'a', day: 1, distance_km: 3 },
            { date: 'b', day: 2, distance_km: 12.5 },
            { date: 'c', day: 3 },
        ]);
        expect(gridMaxKm(cells)).toBe(12.5);
        expect(gridMaxKm(cellsFor([{ date: 'a', day: 1 }]))).toBe(1);
    });
});

describe('consistencyOf', () => {
    it('sums runs and km, and counts ran days across the month', () => {
        const cells = cellsFor([
            { date: '2026-05-01', day: 1, distance_km: 5 },
            { date: '2026-05-02', day: 2 },
            {
                date: '2026-05-03',
                day: 3,
                distance_km: 8,
                runs: [run(), run()],
            },
        ]);
        expect(consistencyOf(cells)).toEqual({
            runs: 3,
            km: 13,
            ranDays: 2,
            daysInMonth: 3,
            longestStreak: 1,
        });
    });

    it('excludes padding days from adjacent months from every count', () => {
        const cells = cellsFor([
            {
                date: '2026-04-30',
                day: 30,
                is_current_month: false,
                distance_km: 99,
            },
            { date: '2026-05-01', day: 1, distance_km: 5 },
        ]);
        const stats = consistencyOf(cells);
        expect(stats.daysInMonth).toBe(1);
        expect(stats.km).toBe(5);
    });

    it('finds the longest streak across a gap in the middle of the month', () => {
        const cells = cellsFor([
            { date: '2026-05-01', day: 1, distance_km: 5 },
            { date: '2026-05-02', day: 2, distance_km: 5 },
            { date: '2026-05-03', day: 3, distance_km: 5 },
            { date: '2026-05-04', day: 4 }, // gap
            { date: '2026-05-05', day: 5, distance_km: 5 },
        ]);
        expect(consistencyOf(cells).longestStreak).toBe(3);
    });

    it('lets a streak run through to the last day of the month', () => {
        const cells = cellsFor([
            { date: '2026-05-29', day: 29, distance_km: 5 },
            { date: '2026-05-30', day: 30, distance_km: 5 },
            { date: '2026-05-31', day: 31, distance_km: 5 },
        ]);
        expect(consistencyOf(cells).longestStreak).toBe(3);
    });

    it('does not let a streak carry across the month edge from a padding day', () => {
        const cells = cellsFor([
            {
                date: '2026-04-30',
                day: 30,
                is_current_month: false,
                distance_km: 5,
            },
            { date: '2026-05-01', day: 1, distance_km: 5 },
            { date: '2026-05-02', day: 2, distance_km: 5 },
        ]);
        expect(consistencyOf(cells).longestStreak).toBe(2);
    });

    it('reports zeroes for a month with no runs', () => {
        const cells = cellsFor([
            { date: '2026-05-01', day: 1 },
            { date: '2026-05-02', day: 2 },
        ]);
        expect(consistencyOf(cells)).toEqual({
            runs: 0,
            km: 0,
            ranDays: 0,
            daysInMonth: 2,
            longestStreak: 0,
        });
    });
});

describe('describeDay', () => {
    it('describes a run-less day', () => {
        const [cell] = cellsFor([{ date: '2026-05-01', day: 1 }]);
        expect(describeDay(cell)).toBe('may 1: no run');
    });

    it('names today', () => {
        const [cell] = cellsFor([
            { date: '2026-05-01', day: 1, is_today: true },
        ]);
        expect(describeDay(cell)).toBe('may 1 (today): no run');
    });

    it('describes a planned rest day', () => {
        const [cell] = cellsFor([
            { date: '2026-05-01', day: 1, effort: 'rest' },
        ]);
        expect(describeDay(cell)).toBe('may 1: planned rest');
    });

    it('describes a single-run day with its distance and effort', () => {
        const [cell] = cellsFor([
            {
                date: '2026-05-01',
                day: 1,
                distance_km: 8.4,
                effort: 'steady',
            },
        ]);
        expect(describeDay(cell)).toBe('may 1: 8.4 km, steady');
    });

    it('leads with the run count on a multi-run day, ahead of the day total', () => {
        const [cell] = cellsFor([
            {
                date: '2026-09-24',
                day: 24,
                distance_km: 13.5,
                effort: 'hard',
                runs: [run({ effort: 'easy' }), run({ effort: 'hard' })],
            },
        ]);
        expect(describeDay(cell)).toBe('sep 24, 2 runs, 13.5 km');
    });
});

describe('barSegments', () => {
    it('gives a single-run day one segment from its own distance and effort', () => {
        const [cell] = cellsFor([
            {
                date: '2026-05-01',
                day: 1,
                distance_km: 8,
                effort: 'hard',
                runs: [run({ activity_id: 9, distance_km: 8, effort: 'hard' })],
            },
        ]);
        expect(barSegments(cell, 10)).toEqual([
            { activityId: 9, effort: 'hard', heightPct: 80 },
        ]);
    });

    it('gives a multi-run day one segment per run, in cell.runs order, each scaled against the grid max', () => {
        const [cell] = cellsFor([
            {
                date: '2026-05-01',
                day: 1,
                distance_km: 12,
                effort: 'hard',
                runs: [
                    run({
                        activity_id: 1,
                        distance_km: 8,
                        effort: 'easy',
                    }),
                    run({
                        activity_id: 2,
                        distance_km: 4,
                        effort: 'hard',
                    }),
                ],
            },
        ]);
        expect(barSegments(cell, 10)).toEqual([
            { activityId: 1, effort: 'easy', heightPct: 80 },
            { activityId: 2, effort: 'hard', heightPct: 40 },
        ]);
    });

    it('floors every segment at the shared minimum height, even a zero-distance run', () => {
        const [cell] = cellsFor([
            {
                date: '2026-05-01',
                day: 1,
                distance_km: 8,
                effort: 'hard',
                runs: [
                    run({ activity_id: 1, distance_km: 8, effort: 'hard' }),
                    run({ activity_id: 2, distance_km: 0, effort: 'easy' }),
                ],
            },
        ]);
        expect(barSegments(cell, 10)[1]).toEqual({
            activityId: 2,
            effort: 'easy',
            heightPct: 8,
        });
    });

    it('falls back to one segment from the day aggregate when the breakdown is thin', () => {
        const [cell] = cellsFor([
            { date: '2026-05-01', day: 1, distance_km: 6, effort: 'steady' },
        ]);
        expect(barSegments(cell, 10)).toEqual([
            { activityId: 0, effort: 'steady', heightPct: 60 },
        ]);
    });

    it('gives a run-less day no segments', () => {
        const [cell] = cellsFor([{ date: '2026-05-01', day: 1 }]);
        expect(barSegments(cell, 10)).toEqual([]);
    });
});
