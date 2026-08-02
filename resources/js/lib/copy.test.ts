import { describe, expect, it } from 'vitest';

import { CTA, MOOD_EMOJI } from './copy';

describe('copy constants', () => {
    it('exposes the canonical CTA verbs', () => {
        expect(CTA.buka).toBe('Buka');
        expect(CTA.semua).toBe('Semua');
        expect(CTA.sambungin).toBe('Sambungin');
        expect(CTA.putus).toBe('Putus');
        expect(CTA.pasang).toBe('Pasang');
        expect(CTA.lagiDipake).toBe('Lagi dipake');
        expect(CTA.bacaUlang).toBe('Baca ulang');
        expect(CTA.mintaTemariBacain).toBe('Minta Temari bacain');
        expect(CTA.sipMulai).toBe('Sip, mulai');
        expect(CTA.cobaLagi).toBe('Coba lagi');
        expect(CTA.batal).toBe('Batal');
    });

    it('covers every expected CTA key', () => {
        expect(Object.keys(CTA).sort()).toEqual(
            [
                'bacaUlang',
                'batal',
                'buka',
                'cobaLagi',
                'lagiDipake',
                'mintaTemariBacain',
                'pasang',
                'putus',
                'sambungin',
                'semua',
                'sipMulai',
            ].sort(),
        );
    });

    it('maps each mood to its emoji', () => {
        expect(MOOD_EMOJI.nyala).toBe('🔥');
        expect(MOOD_EMOJI.enteng).toBe('🌸');
        expect(MOOD_EMOJI.oleng).toBe('⚡');
        expect(MOOD_EMOJI.lemes).toBe('💧');
        expect(MOOD_EMOJI.mumet).toBe('🌀');
        expect(MOOD_EMOJI.adem).toBe('🍃');
    });

    it('covers every expected mood key', () => {
        expect(Object.keys(MOOD_EMOJI).sort()).toEqual(
            ['adem', 'enteng', 'lemes', 'mumet', 'nyala', 'oleng'].sort(),
        );
    });
});
