const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

/** `2026-08-15` -> `15 Aug`. Parsed as a naive date, never shifted by a timezone. */
export function shortDate(iso: string): string {
    const [, m, d] = iso.split('-').map(Number);
    return `${d} ${MONTHS[m - 1]}`;
}

/** `2026-08-15` -> `Aug 2026`. */
export function monthYear(iso: string): string {
    const [y, m] = iso.split('-').map(Number);
    return `${MONTHS[m - 1]} ${y}`;
}

/** Seconds -> `1:41:26` or `21:34`. */
export function duration(sec: number): string {
    const s = Math.round(sec);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const rest = s % 60;
    const pad = (n: number) => String(n).padStart(2, '0');
    return h > 0 ? `${h}:${pad(m)}:${pad(rest)}` : `${m}:${pad(rest)}`;
}

/** Seconds per km -> `4:58`. */
export function pace(secPerKm: number): string {
    const s = Math.round(secPerKm);
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

/** Voice rule: one decimal place is the ceiling, and a trailing `.0` is dropped. */
export function num(value: number, places = 1): string {
    const fixed = value.toFixed(places);
    return fixed.endsWith('.0') ? fixed.slice(0, -2) : fixed;
}

/** Signed delta for a "since a year ago" readout. */
export function signed(value: number, places = 1): string {
    return `${value > 0 ? '+' : value < 0 ? '−' : ''}${num(Math.abs(value), places)}`;
}
