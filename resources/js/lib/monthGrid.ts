import { isoDateLocal, parseNaiveLocalDate } from '@/lib/pace';

/**
 * Monday-first, matching every other weekday row in the app. Carries the full
 * name as well: the initials repeat (T/T, S/S), so they cannot key a list, and
 * a screen reader needs the word rather than the letter.
 */
export const WEEKDAYS = [
    { name: 'Monday', initial: 'M' },
    { name: 'Tuesday', initial: 'T' },
    { name: 'Wednesday', initial: 'W' },
    { name: 'Thursday', initial: 'T' },
    { name: 'Friday', initial: 'F' },
    { name: 'Saturday', initial: 'S' },
    { name: 'Sunday', initial: 'S' },
] as const;

/**
 * The six-week block a month is drawn in, each day as a naive Y-m-d. Always
 * six rows so the popover never changes height as the month changes, and
 * Monday-first so it reads the same way as History's calendar.
 */
export function monthGrid(iso: string): string[][] {
    const first = parseNaiveLocalDate(iso);
    if (first === null) {
        return [];
    }
    first.setDate(1);

    // getDay() is Sunday-first; shift so Monday is column 0.
    const lead = (first.getDay() + 6) % 7;
    const start = new Date(first);
    start.setDate(1 - lead);

    const weeks: string[][] = [];
    const cursor = new Date(start);
    for (let week = 0; week < 6; week++) {
        const days: string[] = [];
        for (let day = 0; day < 7; day++) {
            days.push(isoDateLocal(cursor));
            cursor.setDate(cursor.getDate() + 1);
        }
        weeks.push(days);
    }
    return weeks;
}

/**
 * The same day-of-month `delta` months away, clamped to the end of a shorter
 * month so stepping from the 31st never skips one.
 */
export function addMonths(iso: string, delta: number): string {
    const date = parseNaiveLocalDate(iso);
    if (date === null) {
        return iso;
    }
    const target = date.getMonth() + delta;
    const clamped = new Date(date.getFullYear(), target, 1);
    const lastDay = new Date(
        clamped.getFullYear(),
        clamped.getMonth() + 1,
        0,
    ).getDate();
    clamped.setDate(Math.min(date.getDate(), lastDay));
    return isoDateLocal(clamped);
}

/** Whether `iso` names a day in the same month as `monthIso`. */
export function isSameMonth(iso: string, monthIso: string): boolean {
    return iso.slice(0, 7) === monthIso.slice(0, 7);
}
