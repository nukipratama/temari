import { describe, expect, it } from 'vitest';
import { MOOD_FACE, MASCOT_GRADIENT, moodRing, moodSigilColor, moodToken } from './mood';
import type { Mood } from '@/types/inertia';

const ALL_MOODS: Mood[] = ['glow', 'bouncy', 'wobble', 'squished', 'spinning', 'dim'];

describe('mood', () => {
    it('exposes a face emoji for every mood', () => {
        ALL_MOODS.forEach((m) => {
            expect(MOOD_FACE[m]).toBeTruthy();
        });
    });

    it('exposes a non-empty MASCOT_GRADIENT class string', () => {
        expect(MASCOT_GRADIENT).toContain('bg-gradient');
    });

    describe('moodToken', () => {
        it.each([
            ['glow', 'glow'],
            ['bouncy', 'bouncy'],
            ['wobble', 'cooked'],
            ['squished', 'squished'],
            ['spinning', 'spinning'],
            ['dim', 'hibernate'],
        ] satisfies Array<[Mood, string]>)('maps %s → %s', (mood, token) => {
            expect(moodToken(mood)).toBe(token);
        });

        it('falls back to hibernate for unknown mood', () => {
            expect(moodToken('unknown' as Mood)).toBe('hibernate');
        });
    });

    describe('moodSigilColor', () => {
        it('returns a hex color for every mood', () => {
            ALL_MOODS.forEach((m) => {
                expect(moodSigilColor(m)).toMatch(/^#[0-9a-f]{6}$/i);
            });
        });

        it('falls back to pasir hex for unknown mood', () => {
            expect(moodSigilColor('unknown' as Mood)).toBe('#8a8478');
        });
    });

    describe('moodRing', () => {
        it('returns ring-mood-* class with /60 opacity', () => {
            expect(moodRing('glow')).toBe('ring-mood-glow/60');
            expect(moodRing('wobble')).toBe('ring-mood-cooked/60');
            expect(moodRing('dim')).toBe('ring-mood-hibernate/60');
        });
    });
});
