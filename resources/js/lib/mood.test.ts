import { describe, expect, it } from 'vitest';
import { MOOD_FACE, moodRing, moodSigilColor, moodToken } from './mood';
import type { Mood } from '@/types/inertia';

const ALL_MOODS: Mood[] = ['nyala', 'enteng', 'lemes', 'oleng', 'mumet', 'adem'];

describe('mood', () => {
    it('exposes a face emoji for every mood', () => {
        ALL_MOODS.forEach((m) => {
            expect(MOOD_FACE[m]).toBeTruthy();
        });
    });

    describe('moodToken', () => {
        it.each(ALL_MOODS.map((m) => [m, m] as [Mood, Mood]))('passes through %s', (mood, token) => {
            expect(moodToken(mood)).toBe(token);
        });
    });

    describe('moodSigilColor', () => {
        it('returns a hex color for every mood', () => {
            ALL_MOODS.forEach((m) => {
                expect(moodSigilColor(m)).toMatch(/^#[0-9a-f]{6}$/i);
            });
        });

        it('falls back to adem grey for unknown mood', () => {
            expect(moodSigilColor('unknown' as Mood)).toBe('#6e7b72');
        });
    });

    describe('moodRing', () => {
        it('returns ring-mood-* class with /60 opacity', () => {
            expect(moodRing('nyala')).toBe('ring-mood-nyala/60');
            expect(moodRing('lemes')).toBe('ring-mood-lemes/60');
            expect(moodRing('adem')).toBe('ring-mood-adem/60');
        });
    });
});
