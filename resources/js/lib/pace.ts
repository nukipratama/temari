// Mirrors App\Services\Run\Metrics\PaceFormatter::format().
export function formatPace(secPerKm: number): string {
    const total = Math.round(secPerKm);
    const m = Math.floor(total / 60);
    const s = total - m * 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
}

// Drops seconds so card-style cells never wrap; use formatDurationHMS for full precision.
export function formatDuration(seconds: number): string {
    const total = Math.round(seconds);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    return h > 0 ? `${h}j ${m}m` : `${m}m`;
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

export function formatIdDate(iso: string | null, format: 'short' | 'long' = 'short'): string {
    if (!iso) return '—';
    const d = new Date(iso);
    if (format === 'long') {
        return d.toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
    }
    return d.toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'short' });
}
