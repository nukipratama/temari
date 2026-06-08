// Mirrors App\Services\Run\Metrics\PaceFormatter::format().
export function formatPace(secPerKm: number): string {
    const total = Math.round(secPerKm);
    const m = Math.floor(total / 60);
    const s = total - m * 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
}

// Distance in meters → "5.00" with em-dash fallback. Stat-row callers add the
// "KM" label themselves.
export function formatKm(distanceM: number | null | undefined, fractionDigits = 2): string {
    if (distanceM == null) return '—';
    return (distanceM / 1000).toFixed(fractionDigits);
}

export function paceSecPerKm(movingTimeSec: number | null | undefined, distanceM: number | null | undefined): number | null {
    if (movingTimeSec == null || distanceM == null || distanceM <= 0) return null;
    return movingTimeSec / (distanceM / 1000);
}

// Full Indonesian words: "2 jam 30 menit" / "30 menit 10 detik" / "45 detik".
// Seconds show only when the duration is under an hour, where they read as
// detail rather than clutter. Use formatDurationHMS for the digital H:MM:SS form.
export function formatDuration(seconds: number): string {
    const total = Math.round(seconds);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    if (h > 0) {
        return m > 0 ? `${h} jam ${m} menit` : `${h} jam`;
    }
    if (m > 0) {
        return s > 0 ? `${m} menit ${s} detik` : `${m} menit`;
    }
    return `${s} detik`;
}

export function formatDurationHMS(seconds: number | null | undefined): string {
    if (seconds == null) return '—';
    const total = Math.round(seconds);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    if (h > 0) {
        return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
    }
    return `${m}:${s.toString().padStart(2, '0')}`;
}

// "5 menit lalu", "2 jam lalu", "kemarin", "3 hari lalu". Falls back to '—' on null/invalid.
export function formatRelativeId(iso: string | null | undefined, now: Date = new Date()): string {
    if (!iso) return '—';
    const d = new Date(iso);
    const ms = now.getTime() - d.getTime();
    if (!Number.isFinite(ms)) return '—';
    // A future or clock-skewed timestamp yields a negative delta; treat it as "just now"
    // rather than letting "-3 jam lalu" leak into the UI.
    if (ms < 0) return 'baru aja';
    const sec = Math.round(ms / 1000);
    if (sec < 60) return 'baru aja';
    const min = Math.floor(sec / 60);
    if (min < 60) return `${min} menit lalu`;
    const h = Math.floor(min / 60);
    if (h < 24) return `${h} jam lalu`;
    const day = Math.floor(h / 24);
    if (day === 1) return 'kemarin';
    if (day < 7) return `${day} hari lalu`;
    const week = Math.floor(day / 7);
    if (week < 5) return `${week} minggu lalu`;
    return formatIdDate(iso, 'short');
}

export function formatIdDate(iso: string | null, format: 'short' | 'long' = 'short'): string {
    if (!iso) return '—';
    const d = new Date(iso);
    if (format === 'long') {
        return d.toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
    }
    return d.toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'short' });
}

/** "Senin, 11 Mei" — long weekday + numeric day + long month, no year. */
export function formatWeekdayDateId(date: Date): string {
    return date.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' });
}

/** "08:30" — 24-hour clock, zero-padded. */
export function formatTimeId(date: Date): string {
    return date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
}

/** "Sen, 11 Mei" — short weekday + numeric day + short month. */
export function formatShortWeekdayDateId(date: Date): string {
    return date.toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short' });
}

/** "11 Mei" — numeric day + short month, no weekday or year. */
export function formatMonthDayId(date: Date): string {
    return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
}

/** "Sen, 11" — short weekday + numeric day only. */
export function formatWeekdayDayId(date: Date): string {
    return date.toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric' });
}

/** "11 Mei 2026" — numeric day + long month + year, no weekday. */
export function formatDayMonthYearId(date: Date): string {
    return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
}

/** "11 Mei 2026" — zero-padded day + short month + year. */
export function formatPaddedDayMonthYearId(date: Date): string {
    return date.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

const ID_MONTH_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'] as const;

// "19 Feb 2026" — parses YYYY-MM-DD from the front of the string so the wall-
// clock date renders as-is, regardless of runtime timezone.
export function formatShortDateId(iso: string | null | undefined): string {
    if (!iso) return '—';
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    if (!match) return iso;
    const [, y, m, d] = match;
    return `${Number(d)} ${ID_MONTH_SHORT[Number(m) - 1]} ${y}`;
}

export function monthsSinceId(iso: string | null | undefined): number | null {
    if (!iso) return null;
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    if (!match) return null;
    const [, y, m] = match;
    const now = new Date();
    return Math.max(0, (now.getFullYear() - Number(y)) * 12 + (now.getMonth() + 1 - Number(m)));
}

// Local-zone Monday-of-week. toISOString() would shift to UTC and roll the date
// across midnight in non-UTC zones — past incidents traced week-snapshot bugs to that.
export function mondayOf(iso: string): Date {
    const d = new Date(iso);
    d.setHours(0, 0, 0, 0);
    const offset = (d.getDay() + 6) % 7;
    d.setDate(d.getDate() - offset);
    return d;
}

export function sundayOf(monday: Date): Date {
    const d = new Date(monday);
    d.setDate(d.getDate() + 6);
    return d;
}

// YYYY-MM-DD composed from local fields — see mondayOf for the why.
export function isoDateLocal(d: Date): string {
    const y = d.getFullYear();
    const m = (d.getMonth() + 1).toString().padStart(2, '0');
    const day = d.getDate().toString().padStart(2, '0');
    return `${y}-${m}-${day}`;
}

/** Today as YYYY-MM-DD in the local zone. */
export function todayLocalIso(): string {
    return isoDateLocal(new Date());
}

/** YYYY-MM-DD for `days` ago in the local zone. */
export function isoDaysAgoLocal(days: number): string {
    const d = new Date();
    d.setDate(d.getDate() - days);
    return isoDateLocal(d);
}

/** First day of the current month as YYYY-MM-DD in the local zone. */
export function isoStartOfMonthLocal(): string {
    const d = new Date();
    return isoDateLocal(new Date(d.getFullYear(), d.getMonth(), 1));
}

// Inverse of formatPace: parses "M:SS" (or "MM:SS") back to seconds-per-km.
// Returns NaN on malformed input so callers can guard with Number.isFinite.
export function parsePaceSec(s: string): number {
    const parts = s.split(':');
    if (parts.length !== 2) return Number.NaN;
    const m = Number(parts[0]);
    const sec = Number(parts[1]);
    if (!Number.isFinite(m) || !Number.isFinite(sec)) return Number.NaN;
    return m * 60 + sec;
}
