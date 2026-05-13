import type { Mood } from '@/types/inertia';

/**
 * Mood ↔ Hutan Pagi token mapping. The PHP code uses original mood constants
 * (`wobble`, `dim`); the palette renames concepts (`cooked`, `hibernate`).
 * These helpers translate so components can use mood-aware Tailwind classes.
 */

export const MOOD_FACE: Record<Mood, string> = {
    glow: '✨',
    bouncy: '🦘',
    wobble: '🥵',
    squished: '🍳',
    spinning: '💫',
    dim: '🌧️',
};

/**
 * Mood → mood-* token name (matches @theme vars in app.css).
 */
export function moodToken(mood: Mood): string {
    switch (mood) {
        case 'glow':
            return 'glow';
        case 'bouncy':
            return 'bouncy';
        case 'wobble':
            return 'cooked';
        case 'squished':
            return 'squished';
        case 'spinning':
            return 'spinning';
        case 'dim':
        default:
            return 'hibernate';
    }
}

/**
 * Hex stroke color for sigil per mood (Hutan Pagi swatches).
 */
export function moodSigilColor(mood: Mood): string {
    switch (mood) {
        case 'glow':
            return '#f4a93b';
        case 'bouncy':
            return '#f08a6a';
        case 'wobble':
            return '#c84f4f';
        case 'squished':
            return '#e2783c';
        case 'spinning':
            return '#6e8aaf';
        case 'dim':
        default:
            return '#8a8478';
    }
}

/**
 * Tailwind ring class per mood for the round mascot bubble.
 */
export function moodRing(mood: Mood): string {
    const token = moodToken(mood);
    return `ring-mood-${token}/60`;
}

/**
 * Tailwind background gradient for the mascot bubble.
 * Soft cream → brand coral base, mood ring overlays it.
 */
export const MASCOT_GRADIENT = 'bg-gradient-to-br from-brand-100 to-brand-300 dark:from-brand-700 dark:to-brand-500';
