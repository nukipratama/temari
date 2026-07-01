import { describe, expect, it } from 'vitest';
import {
    formatDayMonthYearId,
    formatDuration,
    formatDurationHMS,
    formatIdDate,
    formatKm,
    formatMonthDayId,
    formatNaiveIdDate,
    formatNaiveRelativeId,
    formatNaiveTimeId,
    formatPaddedDayMonthYearId,
    formatPace,
    formatRelativeId,
    formatShortDateTimeId,
    formatShortWeekdayDateId,
    formatTimeId,
    formatWeekdayDateId,
    formatWeekdayDayId,
    isoDateLocal,
    isoDaysAgoLocal,
    isoStartOfMonthLocal,
    isoStartOfWeekLocal,
    mondayOf,
    paceSecPerKm,
    parseNaiveLocalDate,
    parsePaceSec,
    sundayOf,
    todayLocalIso,
} from './pace';

describe('formatPace', () => {
    it("formats whole minutes as M'SS\"", () => {
        expect(formatPace(360)).toBe('6:00');
    });

    it('pads seconds to 2 digits', () => {
        expect(formatPace(305)).toBe('5:05');
    });

    it('rounds fractional seconds', () => {
        expect(formatPace(305.49)).toBe('5:05');
        expect(formatPace(305.5)).toBe('5:06');
    });

    it('handles sub-minute pace', () => {
        expect(formatPace(45)).toBe('0:45');
    });
});

describe('formatDuration', () => {
    it('spells sub-hour durations with menit + detik', () => {
        expect(formatDuration(630)).toBe('10 menit 30 detik');
    });

    it('drops detik when the minute is whole', () => {
        expect(formatDuration(600)).toBe('10 menit');
    });

    it('formats hours as "H jam M menit", dropping seconds', () => {
        expect(formatDuration(7320)).toBe('2 jam 2 menit');
    });

    it('drops menit when the hour is whole', () => {
        expect(formatDuration(7200)).toBe('2 jam');
    });

    it('falls back to detik-only under a minute', () => {
        expect(formatDuration(45)).toBe('45 detik');
    });
});

describe('formatDurationHMS', () => {
    it('returns dash for null/undefined', () => {
        expect(formatDurationHMS(null)).toBe('—');
        expect(formatDurationHMS(undefined)).toBe('—');
    });

    it('formats with hours as H:MM:SS', () => {
        expect(formatDurationHMS(3725)).toBe('1:02:05');
    });

    it('formats without hours as M:SS', () => {
        expect(formatDurationHMS(125)).toBe('2:05');
    });
});

describe('formatKm', () => {
    it('returns "—" for null/undefined', () => {
        expect(formatKm(null)).toBe('—');
        expect(formatKm(undefined)).toBe('—');
    });

    it('converts meters to km with 2 decimals by default', () => {
        expect(formatKm(5000)).toBe('5.00');
        expect(formatKm(10240.5)).toBe('10.24');
    });

    it('honors a custom fractionDigits', () => {
        expect(formatKm(5230, 1)).toBe('5.2');
    });

    it('treats 0 as a real value (not null)', () => {
        expect(formatKm(0)).toBe('0.00');
    });
});

describe('paceSecPerKm', () => {
    it('returns null when either input is null/undefined', () => {
        expect(paceSecPerKm(null, 5000)).toBeNull();
        expect(paceSecPerKm(1800, null)).toBeNull();
        expect(paceSecPerKm(undefined, undefined)).toBeNull();
    });

    it('returns null on zero/negative distance (no divide-by-zero)', () => {
        expect(paceSecPerKm(1800, 0)).toBeNull();
        expect(paceSecPerKm(1800, -100)).toBeNull();
    });

    it('computes sec per km for a 30-min 5K → 360', () => {
        expect(paceSecPerKm(1800, 5000)).toBe(360);
    });
});

describe('formatIdDate', () => {
    it('returns dash for null', () => {
        expect(formatIdDate(null)).toBe('—');
    });

    it('returns short format by default with weekday', () => {
        const result = formatIdDate('2026-05-11T08:00:00');
        expect(result).toMatch(/^\w+,\s\d{2}\s\w+$/);
    });

    it('returns long format when requested', () => {
        const result = formatIdDate('2026-05-11T08:00:00', 'long');
        expect(result).toMatch(/\d{4}/);
    });

    it('returns dash for non-parseable input', () => {
        expect(formatIdDate('totally-not-a-date')).toBe('—');
    });
});

describe('formatNaiveIdDate', () => {
    it('renders the as-recorded date even when an offset would shift it across midnight', () => {
        // new Date() would read this as 2026-05-12T02:00Z and render 12 Mei in a
        // UTC runtime; the component parse must keep the wall-clock 11 Mei.
        expect(formatNaiveIdDate('2026-05-11T18:00:00-08:00')).toContain('11');
        expect(formatNaiveIdDate('2026-05-11T18:00:00.000000Z')).toContain('11');
    });

    it('returns dash for null and non-parseable input', () => {
        expect(formatNaiveIdDate(null)).toBe('—');
        expect(formatNaiveIdDate('totally-not-a-date')).toBe('—');
    });
});

describe('parseNaiveLocalDate', () => {
    it('builds a local Date from the string components, ignoring any offset', () => {
        const d = parseNaiveLocalDate('2026-05-11T18:30:15-08:00');
        expect(d).not.toBeNull();
        expect(d?.getFullYear()).toBe(2026);
        expect(d?.getMonth()).toBe(4);
        expect(d?.getDate()).toBe(11);
        expect(d?.getHours()).toBe(18);
        expect(d?.getMinutes()).toBe(30);
        expect(d?.getSeconds()).toBe(15);
    });

    it('defaults a date-only string to local midnight', () => {
        const d = parseNaiveLocalDate('2026-05-11');
        expect(d?.getHours()).toBe(0);
        expect(d?.getDate()).toBe(11);
    });

    it('returns null when the string does not lead with a date', () => {
        expect(parseNaiveLocalDate('totally-not-a-date')).toBeNull();
    });
});

describe('formatRelativeId', () => {
    const now = new Date('2026-05-20T12:00:00.000Z');

    it('returns dash for null / undefined', () => {
        expect(formatRelativeId(null, now)).toBe('—');
        expect(formatRelativeId(undefined, now)).toBe('—');
    });

    it('returns dash for non-parseable input', () => {
        expect(formatRelativeId('totally-not-a-date', now)).toBe('—');
    });

    it('returns "baru aja" for under a minute', () => {
        const iso = new Date(now.getTime() - 30 * 1000).toISOString();
        expect(formatRelativeId(iso, now)).toBe('baru aja');
    });

    it('returns minutes for under an hour', () => {
        const iso = new Date(now.getTime() - 5 * 60 * 1000).toISOString();
        expect(formatRelativeId(iso, now)).toBe('5 menit lalu');
    });

    it('returns hours for under a day', () => {
        const iso = new Date(now.getTime() - 3 * 60 * 60 * 1000).toISOString();
        expect(formatRelativeId(iso, now)).toBe('3 jam lalu');
    });

    it('returns "kemarin" for exactly one day', () => {
        const iso = new Date(now.getTime() - 25 * 60 * 60 * 1000).toISOString();
        expect(formatRelativeId(iso, now)).toBe('kemarin');
    });

    it('returns days for under a week', () => {
        const iso = new Date(now.getTime() - 3 * 24 * 60 * 60 * 1000).toISOString();
        expect(formatRelativeId(iso, now)).toBe('3 hari lalu');
    });

    it('returns weeks for under five weeks', () => {
        const iso = new Date(now.getTime() - 14 * 24 * 60 * 60 * 1000).toISOString();
        expect(formatRelativeId(iso, now)).toBe('2 minggu lalu');
    });

    it('falls back to short date for old timestamps', () => {
        const iso = new Date(now.getTime() - 60 * 24 * 60 * 60 * 1000).toISOString();
        expect(formatRelativeId(iso, now)).toMatch(/\w+,\s\d{2}\s\w+/);
    });

    it('returns "—" for null / invalid input', () => {
        expect(formatRelativeId(null)).toBe('—');
        expect(formatRelativeId('not-a-date')).toBe('—');
    });

    it('returns "baru aja" for under a minute', () => {
        const iso = new Date(now.getTime() - 5 * 1000).toISOString();
        expect(formatRelativeId(iso, now)).toBe('baru aja');
    });

    it('clamps a future / clock-skewed timestamp to "baru aja" (no negative units)', () => {
        const future = new Date(now.getTime() + 3 * 60 * 60 * 1000).toISOString();
        expect(formatRelativeId(future, now)).toBe('baru aja');
    });

});

describe('formatNaiveRelativeId', () => {
    it('measures the delta from the as-recorded wall clock, ignoring any offset', () => {
        // Wall clock 09:00 vs a local-now of 12:00 → 3 jam lalu. new Date() would
        // shift the -08:00 input to 17:00Z and clamp it to "baru aja" instead.
        const localNow = new Date(2026, 4, 20, 12, 0, 0);
        expect(formatNaiveRelativeId('2026-05-20T09:00:00-08:00', localNow)).toBe('3 jam lalu');
    });

    it('returns dash for null and non-parseable input', () => {
        expect(formatNaiveRelativeId(null)).toBe('—');
        expect(formatNaiveRelativeId('totally-not-a-date')).toBe('—');
    });

    it('falls back to the naive short date for old timestamps', () => {
        const localNow = new Date(2026, 4, 20, 12, 0, 0);
        expect(formatNaiveRelativeId('2026-01-02T06:30:00.000000Z', localNow)).toContain('2');
    });
});

describe('parsePaceSec', () => {
    it('parses "M:SS" into seconds', () => {
        expect(parsePaceSec('5:05')).toBe(305);
        expect(parsePaceSec('6:00')).toBe(360);
    });

    it('round-trips with formatPace', () => {
        expect(parsePaceSec(formatPace(305))).toBe(305);
        expect(formatPace(parsePaceSec('4:30'))).toBe('4:30');
    });

    it('returns NaN on malformed input', () => {
        expect(parsePaceSec('not-a-pace')).toBeNaN();
        expect(parsePaceSec('5')).toBeNaN();
        expect(parsePaceSec('5:05:05')).toBeNaN();
        expect(parsePaceSec('a:b')).toBeNaN();
    });
});

describe('date/time format variants', () => {
    // 11 May 2026 is a Monday at 08:30 local.
    const d = new Date(2026, 4, 11, 8, 30);

    it('formatWeekdayDateId: long weekday + day + long month', () => {
        expect(formatWeekdayDateId(d)).toBe('Senin, 11 Mei');
    });

    it('formatTimeId: zero-padded HH:MM', () => {
        expect(formatTimeId(d)).toBe('08.30');
    });

    it('formatShortWeekdayDateId: short weekday + day + short month', () => {
        expect(formatShortWeekdayDateId(d)).toBe('Sen, 11 Mei');
    });

    it('formatMonthDayId: day + short month', () => {
        expect(formatMonthDayId(d)).toBe('11 Mei');
    });

    it('formatWeekdayDayId: short weekday + day', () => {
        expect(formatWeekdayDayId(d)).toBe('Sen, 11');
    });

    it('formatDayMonthYearId: day + long month + year', () => {
        expect(formatDayMonthYearId(d)).toBe('11 Mei 2026');
    });

    it('formatPaddedDayMonthYearId: padded day + short month + year', () => {
        expect(formatPaddedDayMonthYearId(d)).toBe('11 Mei 2026');
    });
});

describe('formatNaiveTimeId', () => {
    it('returns null for null / undefined / date-only / malformed', () => {
        expect(formatNaiveTimeId(null)).toBeNull();
        expect(formatNaiveTimeId(undefined)).toBeNull();
        expect(formatNaiveTimeId('2026-06-09')).toBeNull();
        expect(formatNaiveTimeId('not-a-date')).toBeNull();
    });

    it('reads the literal wall-clock HH.MM from the string', () => {
        expect(formatNaiveTimeId('2026-06-09T06:52:00')).toBe('06.52');
        expect(formatNaiveTimeId('2026-06-09T06:52')).toBe('06.52');
    });

    it('does NOT shift the hour when the string carries a UTC Z (no timezone math)', () => {
        // Laravel serializes the naive datetime cast with a trailing Z. The hour
        // must render as recorded (06.52), never reinterpreted as UTC and shifted.
        expect(formatNaiveTimeId('2026-06-09T06:52:54.000000Z')).toBe('06.52');
    });

    it('does NOT shift the hour under an explicit offset suffix', () => {
        expect(formatNaiveTimeId('2026-06-09T23:15:00+09:00')).toBe('23.15');
    });
});

describe('formatShortDateTimeId', () => {
    it('returns dash for null / undefined', () => {
        expect(formatShortDateTimeId(null)).toBe('—');
        expect(formatShortDateTimeId(undefined)).toBe('—');
    });

    it('combines short date and naive wall-clock time', () => {
        expect(formatShortDateTimeId('2026-06-09T06:52:00')).toBe('9 Jun 2026 · 06.52');
    });

    it('drops the time half for a date-only string', () => {
        expect(formatShortDateTimeId('2026-06-09')).toBe('9 Jun 2026');
    });

    it('renders the as-recorded hour even with a trailing Z', () => {
        expect(formatShortDateTimeId('2026-06-09T06:52:54.000000Z')).toBe('9 Jun 2026 · 06.52');
    });
});

describe('local-zone ISO date helpers', () => {
    it('todayLocalIso returns YYYY-MM-DD for the local current date', () => {
        expect(todayLocalIso()).toBe(isoDateLocal(new Date()));
    });

    it('isoDaysAgoLocal subtracts whole days in the local zone', () => {
        const d = new Date();
        d.setDate(d.getDate() - 7);
        expect(isoDaysAgoLocal(7)).toBe(isoDateLocal(d));
    });

    it('isoStartOfMonthLocal returns the first of the current month', () => {
        const now = new Date();
        expect(isoStartOfMonthLocal()).toBe(isoDateLocal(new Date(now.getFullYear(), now.getMonth(), 1)));
    });

    it('isoStartOfWeekLocal returns the Monday of the current week', () => {
        expect(isoStartOfWeekLocal()).toBe(isoDateLocal(mondayOf(todayLocalIso())));
    });
});

describe('mondayOf / sundayOf / isoDateLocal', () => {
    it('mondayOf snaps any weekday back to its Monday at local 00:00', () => {
        // Saturday 2026-05-23 → Monday 2026-05-18.
        const monday = mondayOf('2026-05-23T15:00:00');
        expect(monday.getFullYear()).toBe(2026);
        expect(monday.getMonth()).toBe(4);
        expect(monday.getDate()).toBe(18);
        expect(monday.getHours()).toBe(0);
    });

    it('mondayOf is idempotent when given a Monday', () => {
        const monday = mondayOf('2026-05-18T10:00:00');
        expect(isoDateLocal(monday)).toBe('2026-05-18');
    });

    it('mondayOf buckets by the as-recorded local day, ignoring a trailing offset', () => {
        // Sunday 2026-06-07 22:00 belongs to the week of Mon 2026-06-01.
        // new Date() would read the -05:00 offset as 2026-06-08T03:00Z and, in a
        // UTC runtime, snap to Mon 2026-06-08 (the WRONG week).
        const monday = mondayOf('2026-06-07T22:00:00-05:00');
        expect(isoDateLocal(monday)).toBe('2026-06-01');
    });

    it('sundayOf advances the given Monday by six days', () => {
        const sunday = sundayOf(new Date(2026, 4, 18));
        expect(isoDateLocal(sunday)).toBe('2026-05-24');
    });

    it('isoDateLocal composes YYYY-MM-DD from local fields (does not roll across UTC)', () => {
        const d = new Date(2026, 0, 3); // 3 January 2026 local
        expect(isoDateLocal(d)).toBe('2026-01-03');
    });
});
