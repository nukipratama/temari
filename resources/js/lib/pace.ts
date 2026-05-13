/**
 * Format a pace in seconds-per-km as M'SS"/km.
 * Mirrors App\Services\Run\Metrics\PaceFormatter::format()
 */
export function formatPace(secPerKm: number): string {
    const total = Math.round(secPerKm);
    const m = Math.floor(total / 60);
    const s = total - m * 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
}

/**
 * Compact run-duration label for card-style displays — drops seconds so the
 * value never wraps inside a tight grid cell. Use `formatDurationHMS` when
 * full precision is wanted (run detail page).
 */
export function formatDuration(seconds: number): string {
    const total = Math.round(seconds);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    return h > 0 ? `${h}j ${m}m` : `${m}m`;
}

/**
 * Format a duration in seconds as "H:MM:SS" (or "M:SS" when h=0). Useful for
 * full-activity moving-time displays.
 */
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

/**
 * Format a date string from Inertia (ISO 8601) as Indonesian short date.
 */
export function formatIdDate(iso: string | null, format: 'short' | 'long' = 'short'): string {
    if (!iso) return '—';
    const d = new Date(iso);
    if (format === 'long') {
        return d.toLocaleDateString('id-ID', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
    }
    return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' });
}
