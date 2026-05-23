import type { Mood } from '@/types/inertia';

export const MOOD_FACE: Record<Mood, string> = {
    nyala: '✨',
    enteng: '🦘',
    lemes: '🥵',
    oleng: '🍳',
    mumet: '💫',
    adem: '🌧️',
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
