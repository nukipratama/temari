import { formatDurationHMS } from '@/lib/pace';

/** PR categories where the recorded value is a duration (e.g. fastest 5km). */
const DISTANCE_CATEGORIES = new Set(['1km', '5km', '10km', '15km', 'half_marathon', 'marathon']);

export const PR_CATEGORY_LABELS: Record<string, string> = {
    '1km': '1 KM',
    '5km': '5 KM',
    '10km': '10 KM',
    '15km': '15 KM',
    half_marathon: 'Half Marathon',
    marathon: 'Marathon',
    best_5min: 'Best 5 minutes',
    best_10min: 'Best 10 minutes',
    best_20min: 'Best 20 minutes',
    best_30min: 'Best 30 minutes',
    best_60min: 'Best 60 minutes',
};

/**
 * Distance PRs read as hh:mm:ss durations (the time to cover that distance).
 * Effort PRs read as min:sec/km pace.
 */
export function formatPrValue(category: string, secs: number): string {
    if (DISTANCE_CATEGORIES.has(category)) {
        return formatDurationHMS(secs);
    }

    return `${Math.floor(secs / 60)}:${(secs % 60).toString().padStart(2, '0')}/km`;
}
