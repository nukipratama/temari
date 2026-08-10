import type { Mood } from '@/types/inertia';

export const MOOD_FACE: Record<Mood, string> = {
    blazing: '✨',
    easy: '🦘',
    gassed: '🥵',
    wobbly: '🍳',
    overloaded: '💫',
    chill: '🌧️',
};

export const MOOD_LABEL: Record<Mood, string> = {
    blazing: 'Blazing',
    easy: 'Easy',
    gassed: 'Gassed',
    wobbly: 'Wobbly',
    overloaded: 'Overloaded',
    chill: 'Chill',
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

// Short cause hint per mood; pairs with MOOD_LABEL in filter/legend rows.
export const MOOD_HINT: Record<Mood, string> = {
    blazing: 'PR or win',
    easy: 'easy pace',
    wobbly: 'HR drift',
    gassed: 'pushed too hard',
    overloaded: 'overdid it',
    chill: 'rest day',
};

export interface MoodOption {
    mood: Mood;
    label: string;
    hint: string;
    /** Tailwind class for the chip swatch. */
    swatchClass: string;
}

export const MOOD_FILTER_OPTIONS: ReadonlyArray<MoodOption> = MOOD_ORDER.map(
    (mood) => ({
        mood,
        label: MOOD_LABEL[mood],
        hint: MOOD_HINT[mood],
        swatchClass: MOOD_FILL[mood],
    }),
);

export function moodToken(mood: Mood): Mood {
    return mood;
}

export function moodSigilColor(mood: Mood): string {
    switch (mood) {
        case 'blazing':
            return '#c9971f';
        case 'easy':
            return '#2f8f63';
        case 'gassed':
            return '#7a2030';
        case 'wobbly':
            return '#b23a4f';
        case 'overloaded':
            return '#6b3fa0';
        case 'chill':
        default:
            return '#55488f';
    }
}

export function moodRing(mood: Mood): string {
    return `ring-mood-${mood}/60`;
}
