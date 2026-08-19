import { describe, expect, it } from 'vitest';

import type { Mood } from '@/types/inertia';

import {
    MOOD_FILL,
    MOOD_FILTER_OPTIONS,
    MOOD_HINT,
    MOOD_LABEL,
    MOOD_ORDER,
    moodSigilColor,
} from './mood';

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

    describe('MOOD_FILTER_OPTIONS', () => {
        it('follows MOOD_ORDER and carries the label, hint and swatch of each mood', () => {
            expect(MOOD_FILTER_OPTIONS.map((o) => o.mood)).toEqual([
                ...MOOD_ORDER,
            ]);
            MOOD_FILTER_OPTIONS.forEach((option) => {
                expect(option.label).toBe(MOOD_LABEL[option.mood]);
                expect(option.hint).toBe(MOOD_HINT[option.mood]);
                expect(option.swatchClass).toBe(MOOD_FILL[option.mood]);
            });
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
