import type { Mood } from '@/types/inertia';

export const MOOD_FACE: Record<Mood, string> = {
    nyala: '✨',
    enteng: '🦘',
    lemes: '🥵',
    oleng: '🍳',
    mumet: '💫',
    adem: '🌧️',
};

export const MOOD_LABEL: Record<Mood, string> = {
    nyala: 'Nyala',
    enteng: 'Enteng',
    lemes: 'Lemes',
    oleng: 'Oleng',
    mumet: 'Mumet',
    adem: 'Adem',
};

// Solid mood fill (bg-mood-{key}); use for persona bar segments + sigil swatches.
export const MOOD_FILL: Record<Mood, string> = {
    nyala: 'bg-mood-nyala',
    enteng: 'bg-mood-enteng',
    lemes: 'bg-mood-lemes',
    oleng: 'bg-mood-oleng',
    mumet: 'bg-mood-mumet',
    adem: 'bg-mood-adem',
};

// Soft tinted fill (bg-mood-{key}-bg); use for chip backgrounds where text sits on top.
export const MOOD_SOFT_FILL: Record<Mood, string> = {
    nyala: 'bg-mood-nyala-bg',
    enteng: 'bg-mood-enteng-bg',
    lemes: 'bg-mood-lemes-bg',
    oleng: 'bg-mood-oleng-bg',
    mumet: 'bg-mood-mumet-bg',
    adem: 'bg-mood-adem-bg',
};

export function moodToken(mood: Mood): Mood {
    return mood;
}

export function moodSigilColor(mood: Mood): string {
    switch (mood) {
        case 'nyala':
            return '#d99a1a';
        case 'enteng':
            return '#c83a76';
        case 'lemes':
            return '#b8302f';
        case 'oleng':
            return '#c46f1c';
        case 'mumet':
            return '#6b4ea8';
        case 'adem':
        default:
            return '#6e7b72';
    }
}

export function moodRing(mood: Mood): string {
    return `ring-mood-${mood}/60`;
}
