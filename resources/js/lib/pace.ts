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

// Builds a Date from the string's own Y-M-D[ H:M:S] components in the runtime's
// local zone, ignoring any trailing Z/offset. Backend datetimes here are naive
// wall-clock values (Strava's start_date_local) that Laravel serializes with a
// misleading trailing Z — `new Date(iso)` would reinterpret them as UTC and
// shift the date/hour for non-WIB viewers. Null when the string doesn't lead
// with a date.
export function parseNaiveLocalDate(iso: string): Date | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/.exec(iso);
    if (!match) return null;
    const [, y, m, d, h, min, s] = match;
    return new Date(Number(y), Number(m) - 1, Number(d), Number(h ?? 0), Number(min ?? 0), Number(s ?? 0));
}

// Shared wording for the relative formatters below. Negative (future/skewed)
// deltas clamp to "baru aja" rather than leaking "-3 jam lalu" into the UI.
function relativeIdFromDelta(ms: number, iso: string, naive: boolean): string {
    if (!Number.isFinite(ms)) return '—';
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
    return naive ? formatNaiveIdDate(iso, 'short') : formatIdDate(iso, 'short');
}

// "5 menit lalu", "2 jam lalu", "kemarin", "3 hari lalu" for TRUE INSTANTS
// (created_at / generated_at / fetched_at style UTC timestamps, or local Dates
// round-tripped through toISOString). Falls back to '—' on null/invalid.
// For naive wall-clock values (start_date_local), use formatNaiveRelativeId.
export function formatRelativeId(iso: string | null | undefined, now: Date = new Date()): string {
    if (!iso) return '—';
    return relativeIdFromDelta(now.getTime() - new Date(iso).getTime(), iso, false);
}

// Relative wording for NAIVE wall-clock values (Strava's start_date_local,
// serialized with a misleading trailing Z): the delta is measured against the
// as-recorded local clock, so a 06:30 run reads "12 jam lalu" at 18:30 local
// instead of being shifted by the viewer's offset.
export function formatNaiveRelativeId(iso: string | null | undefined, now: Date = new Date()): string {
    if (!iso) return '—';
    const d = parseNaiveLocalDate(iso);
    if (d === null) return '—';
    return relativeIdFromDelta(now.getTime() - d.getTime(), iso, true);
}

function idDateFromDate(d: Date, format: 'short' | 'long'): string {
    if (Number.isNaN(d.getTime())) return '—';
    if (format === 'long') {
        return d.toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
    }
    return d.toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'short' });
}

// Weekday + date for TRUE INSTANTS (see formatRelativeId). For naive
// wall-clock values (start_date_local, pr.set_at), use formatNaiveIdDate.
export function formatIdDate(iso: string | null, format: 'short' | 'long' = 'short'): string {
    if (!iso) return '—';
    return idDateFromDate(new Date(iso), format);
}

// Weekday + date for NAIVE wall-clock values: component-parsed so the
// as-recorded date can't roll across midnight under the viewer's offset.
export function formatNaiveIdDate(iso: string | null, format: 'short' | 'long' = 'short'): string {
    if (!iso) return '—';
    const d = parseNaiveLocalDate(iso);
    if (d === null) return '—';
    return idDateFromDate(d, format);
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

// "06.52" — zero-padded HH.MM read straight from the naive datetime string, so
// the as-recorded wall-clock renders identically in any runtime timezone. Never
// goes through `new Date(iso)`, which would parse a trailing Z/offset as UTC and
// shift the hour. Returns null when the string carries no time component.
export function formatNaiveTimeId(iso: string | null | undefined): string | null {
    if (!iso) return null;
    const match = /T(\d{2}):(\d{2})/.exec(iso);
    if (!match) return null;
    const [, h, m] = match;
    return `${h}.${m}`;
}

// "19 Feb 2026 · 06.52" — short date + naive wall-clock time, both parsed from
// the string components so neither the date nor the hour shifts under a non-WIB
// runtime. Drops the time half when the string is date-only.
export function formatShortDateTimeId(iso: string | null | undefined): string {
    const date = formatShortDateId(iso);
    const time = formatNaiveTimeId(iso);
    return time === null ? date : `${date} · ${time}`;
}

export function monthsSinceId(iso: string | null | undefined): number | null {
    if (!iso) return null;
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    if (!match) return null;
    const [, y, m] = match;
    const now = new Date();
    return Math.max(0, (now.getFullYear() - Number(y)) * 12 + (now.getMonth() + 1 - Number(m)));
}

// Local-zone Monday-of-week. Parses the iso by its own wall-clock components
// (not new Date(iso), which reads a trailing Z/offset as UTC and can roll a
// late-evening run into the next day's week for a non-UTC viewer) so a run is
// always bucketed into the week it was actually run. Falls back to new Date for
// inputs the naive parser can't read.
export function mondayOf(iso: string): Date {
    const d = parseNaiveLocalDate(iso) ?? new Date(iso);
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

/** Monday of the current week as YYYY-MM-DD in the local zone — see mondayOf for the why. */
export function isoStartOfWeekLocal(): string {
    return isoDateLocal(mondayOf(todayLocalIso()));
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
