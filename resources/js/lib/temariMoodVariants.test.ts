import { describe, expect, it } from 'vitest';
import { MOOD_VARIANTS, variantFor } from './temariMoodVariants';
import type { Mood } from '@/types/inertia';

const ALL_MOODS: Mood[] = ['nyala', 'enteng', 'lemes', 'oleng', 'mumet', 'adem'];

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
        expect(variantFor('nyala')).toBe(MOOD_VARIANTS.nyala);
        expect(variantFor('mumet')).toBe(MOOD_VARIANTS.mumet);
    });

    it('variantFor falls back to adem for an unknown mood', () => {
        expect(variantFor('unknown' as Mood)).toBe(MOOD_VARIANTS.adem);
    });

    it('every mood declares an accessory + particle slot', () => {
        ALL_MOODS.forEach((m) => {
            expect(MOOD_VARIANTS[m].accessory).not.toBeUndefined();
            expect(MOOD_VARIANTS[m].particles).not.toBeUndefined();
        });
    });

    it('maps moods to their signature accessory + particles', () => {
        expect(MOOD_VARIANTS.nyala.accessory).toBe('medal');
        expect(MOOD_VARIANTS.nyala.particles).toBe('sparkles');
        expect(MOOD_VARIANTS.adem.accessory).toBe('nightcap');
        expect(MOOD_VARIANTS.adem.particles).toBe('zzz');
        expect(MOOD_VARIANTS.lemes.accessory).toBe('towel');
        expect(MOOD_VARIANTS.lemes.particles).toBe('droplets');
    });
});
