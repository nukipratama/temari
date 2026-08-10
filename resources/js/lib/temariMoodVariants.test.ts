import { describe, expect, it } from 'vitest';

import type { Mood } from '@/types/inertia';

import { MOOD_VARIANTS, variantFor } from './temariMoodVariants';

const ALL_MOODS: Mood[] = [
    'blazing',
    'easy',
    'gassed',
    'wobbly',
    'overloaded',
    'chill',
];

describe('temariMoodVariants', () => {
    it('exposes a variant for every mood', () => {
        ALL_MOODS.forEach((m) => {
            expect(MOOD_VARIANTS[m]).toBeDefined();
        });
    });

    it('every variant uses a hex moodColor', () => {
        ALL_MOODS.forEach((m) => {
            expect(MOOD_VARIANTS[m].moodColor).toMatch(/^#[0-9a-f]{6}$/i);
        });
    });

    it('variantFor returns the right variant', () => {
        expect(variantFor('blazing')).toBe(MOOD_VARIANTS.blazing);
        expect(variantFor('overloaded')).toBe(MOOD_VARIANTS.overloaded);
    });

    it('variantFor falls back to chill for an unknown mood', () => {
        expect(variantFor('unknown' as Mood)).toBe(MOOD_VARIANTS.chill);
    });

    it('every mood declares an accessory + particle slot', () => {
        ALL_MOODS.forEach((m) => {
            expect(MOOD_VARIANTS[m].accessory).not.toBeUndefined();
            expect(MOOD_VARIANTS[m].particles).not.toBeUndefined();
        });
    });

    it('maps moods to their signature accessory + particles', () => {
        expect(MOOD_VARIANTS.blazing.accessory).toBe('medal');
        expect(MOOD_VARIANTS.blazing.particles).toBe('sparkles');
        expect(MOOD_VARIANTS.chill.accessory).toBe('nightcap');
        expect(MOOD_VARIANTS.chill.particles).toBe('zzz');
        expect(MOOD_VARIANTS.gassed.accessory).toBe('towel');
        expect(MOOD_VARIANTS.gassed.particles).toBe('droplets');
    });
});
