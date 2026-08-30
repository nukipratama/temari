import { describe, expect, it } from 'vitest';

import type { Mood } from '@/types/inertia';

import { dominantMood, MOOD_HINT, MOOD_ORDER, moodSigilColor } from './mood';

const ALL_MOODS: Mood[] = [
    'blazing',
    'easy',
    'gassed',
    'wobbly',
    'overloaded',
    'chill',
];

describe('mood', () => {
    describe('MOOD_ORDER', () => {
        it('lists every mood exactly once', () => {
            const byName = (a: Mood, b: Mood) => a.localeCompare(b);
            expect([...MOOD_ORDER].sort(byName)).toEqual(
                [...ALL_MOODS].sort(byName),
            );
        });
    });

    describe('MOOD_HINT', () => {
        it('exposes a non-empty hint for every mood', () => {
            ALL_MOODS.forEach((m) => {
                expect(MOOD_HINT[m]).toBeTruthy();
            });
        });
    });

    describe('dominantMood', () => {
        it('picks the most frequent mood, ties broken by MOOD_ORDER', () => {
            expect(dominantMood(['chill', 'blazing', 'chill', null])).toBe(
                'chill',
            );
        });

        it('is null when nothing scores', () => {
            expect(dominantMood([null, null])).toBeNull();
            expect(dominantMood([])).toBeNull();
        });
    });

    describe('moodSigilColor', () => {
        it('returns a hex color for every mood', () => {
            ALL_MOODS.forEach((m) => {
                expect(moodSigilColor(m)).toMatch(/^#[0-9a-f]{6}$/i);
            });
        });

        it('falls back to chill grey for unknown mood', () => {
            expect(moodSigilColor('unknown' as Mood)).toBe('#55488f');
        });
    });
});
