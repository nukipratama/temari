import { describe, expect, it } from 'vitest';

import { addMonths, isSameMonth, monthGrid, WEEKDAYS } from './monthGrid';

describe('monthGrid', () => {
    it('always draws six weeks, so the popover never changes height', () => {
        for (const iso of ['2026-02-10', '2026-08-01', '2026-11-30']) {
            const weeks = monthGrid(iso);
            expect(weeks).toHaveLength(6);
            expect(weeks.every((w) => w.length === 7)).toBe(true);
        }
    });

    it('starts each week on a Monday', () => {
        for (const week of monthGrid('2026-09-15')) {
            // 2026-09-15 is a Tuesday; every row must still open on a Monday.
            const day = new Date(`${week[0]}T00:00:00`).getDay();
            expect(day).toBe(1);
        }
    });

    it('leads with the trailing days of the previous month', () => {
        // 2026-09-01 is a Tuesday, so exactly one leading day is borrowed.
        const weeks = monthGrid('2026-09-01');
        expect(weeks[0][0]).toBe('2026-08-31');
        expect(weeks[0][1]).toBe('2026-09-01');
    });

    it('covers every day of the month it is given', () => {
        const flat = monthGrid('2026-02-01').flat();
        for (let day = 1; day <= 28; day++) {
            expect(flat).toContain(`2026-02-${String(day).padStart(2, '0')}`);
        }
    });

    it('returns nothing for an unparseable date', () => {
        expect(monthGrid('not-a-date')).toEqual([]);
    });
});

describe('addMonths', () => {
    it('steps forward and back', () => {
        expect(addMonths('2026-09-15', 1)).toBe('2026-10-15');
        expect(addMonths('2026-09-15', -1)).toBe('2026-08-15');
    });

    it('rolls across a year boundary', () => {
        expect(addMonths('2026-12-10', 1)).toBe('2027-01-10');
        expect(addMonths('2026-01-10', -1)).toBe('2025-12-10');
    });

    it('clamps to the end of a shorter month rather than skipping it', () => {
        // Stepping from the 31st must land in February, not skip to March.
        expect(addMonths('2026-01-31', 1)).toBe('2026-02-28');
        expect(addMonths('2026-03-31', -1)).toBe('2026-02-28');
    });

    it('returns the input unchanged when it cannot be parsed', () => {
        expect(addMonths('nope', 1)).toBe('nope');
    });
});

describe('isSameMonth', () => {
    it('compares year and month only', () => {
        expect(isSameMonth('2026-09-01', '2026-09-30')).toBe(true);
        expect(isSameMonth('2026-08-31', '2026-09-01')).toBe(false);
        expect(isSameMonth('2025-09-01', '2026-09-01')).toBe(false);
    });
});

describe('WEEKDAYS', () => {
    it('runs Monday to Sunday with unique names, since the initials repeat', () => {
        expect(WEEKDAYS.map((d) => d.initial)).toEqual([
            'M',
            'T',
            'W',
            'T',
            'F',
            'S',
            'S',
        ]);
        expect(new Set(WEEKDAYS.map((d) => d.name)).size).toBe(7);
    });
});
