import type { Mood } from '@/types/inertia';

import { PALETTE } from '@/lib/chartTokens';

export const MOOD_LABEL: Record<Mood, string> = {
    blazing: 'blazing',
    easy: 'easy',
    gassed: 'gassed',
    wobbly: 'wobbly',
    overloaded: 'overloaded',
    chill: 'chill',
};

// Solid mood fill (bg-mood-{key}); use for persona bar segments + sigil swatches.
export const MOOD_FILL: Record<Mood, string> = {
    blazing: 'bg-mood-blazing',
    easy: 'bg-mood-easy',
    gassed: 'bg-mood-gassed',
    wobbly: 'bg-mood-wobbly',
    overloaded: 'bg-mood-overloaded',
    chill: 'bg-mood-chill',
};

// Soft tinted fill (bg-mood-{key}-bg); use for chip backgrounds where text sits on top.
export const MOOD_SOFT_FILL: Record<Mood, string> = {
    blazing: 'bg-mood-blazing-bg',
    easy: 'bg-mood-easy-bg',
    gassed: 'bg-mood-gassed-bg',
    wobbly: 'bg-mood-wobbly-bg',
    overloaded: 'bg-mood-overloaded-bg',
    chill: 'bg-mood-chill-bg',
};

// Canonical mood ordering for legends + filter rows (best-day → rest-day).
export const MOOD_ORDER: ReadonlyArray<Mood> = [
    'blazing',
    'easy',
    'wobbly',
    'gassed',
    'overloaded',
    'chill',
];

/**
 * The most frequent mood among a set of runs, ties broken by MOOD_ORDER
 * (best-day → rest-day) so the pick is deterministic. Null moods (a run with
 * no post-run story line yet) don't count. Null when nothing scores.
 */
export function dominantMood(moods: ReadonlyArray<Mood | null>): Mood | null {
    const counts = new Map<Mood, number>();
    for (const mood of moods) {
        if (mood === null) continue;
        counts.set(mood, (counts.get(mood) ?? 0) + 1);
    }

    let dominant: Mood | null = null;
    let topCount = 0;
    for (const mood of MOOD_ORDER) {
        const count = counts.get(mood) ?? 0;
        if (count > topCount) {
            topCount = count;
            dominant = mood;
        }
    }

    return dominant;
}

export function moodSigilColor(mood: Mood): string {
    switch (mood) {
        case 'blazing':
            return PALETTE.citrus;
        case 'easy':
            return PALETTE.leaf;
        case 'gassed':
            return PALETTE.gassed;
        case 'wobbly':
            return PALETTE.ember;
        case 'overloaded':
            return PALETTE.overloaded;
        case 'chill':
        default:
            return PALETTE.chill;
    }
}
