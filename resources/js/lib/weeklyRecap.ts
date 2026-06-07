import { formatMonthDayId } from '@/lib/pace';

/**
 * Display helpers for the "Minggu Kamu" weekly recap, shared between the live
 * `RecapCard` and the canvas share renderer so the km delta phrasing and the
 * week-range label can never drift between the two surfaces.
 */

/**
 * The km-vs-last-week line. Null delta (no comparable baseline) reads as a
 * first-week nudge; a 0% delta reads as "sama" rather than a bare "+0%" / "-0%".
 */
export function weeklyDeltaLabel(deltaPct: number | null): string {
    if (deltaPct === null) {
        return 'minggu pertama kamu';
    }
    if (deltaPct === 0) {
        return 'sama kayak minggu lalu';
    }
    const sign = deltaPct > 0 ? '+' : '-';
    return `${sign}${Math.abs(deltaPct)}% dari minggu lalu`;
}

/** "naik" / "turun" / null — drives the delta line's color + arrow tone. */
export function weeklyDeltaDirection(deltaPct: number | null): 'up' | 'down' | 'flat' {
    if (deltaPct === null || deltaPct === 0) {
        return 'flat';
    }
    return deltaPct > 0 ? 'up' : 'down';
}

/** "11 Mei - 17 Mei" from the recap's ISO week_start / week_end. */
export function weekRangeLabel(weekStart: string, weekEnd: string): string {
    const start = parseLocalIsoDate(weekStart);
    const end = parseLocalIsoDate(weekEnd);
    if (start === null || end === null) {
        return '';
    }
    return `${formatMonthDayId(start)} - ${formatMonthDayId(end)}`;
}

/** "3 minggu beruntun" / null when the streak is under 2 (nothing to brag yet). */
export function streakLabel(weeks: number): string | null {
    if (weeks < 2) {
        return null;
    }
    return `${weeks} minggu beruntun`;
}

// Parse YYYY-MM-DD into a local-zone Date (midnight), avoiding the UTC shift
// `new Date('YYYY-MM-DD')` applies. Null on malformed input.
function parseLocalIsoDate(iso: string): Date | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    if (!match) {
        return null;
    }
    const [, y, m, d] = match;
    return new Date(Number(y), Number(m) - 1, Number(d));
}
