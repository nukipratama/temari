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

// Drops seconds so card-style cells never wrap; use formatDurationHMS for full precision.
export function formatDuration(seconds: number): string {
    const total = Math.round(seconds);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    if (h === 0) return `${m}m`;
    return m === 0 ? `${h}j` : `${h}j ${m}m`;
}

export function formatDurationHMS(seconds: number | null | undefined): string {
    if (seconds == null) return '—';
    const total = Math.round(seconds);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total - h * 3600 - m * 60;
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
