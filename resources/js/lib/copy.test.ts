import { describe, expect, it } from 'vitest';

import { CTA, MOOD_EMOJI } from './copy';

describe('copy constants', () => {
    it('exposes the canonical CTA verbs', () => {
        expect(CTA.buka).toBe('Open');
        expect(CTA.semua).toBe('See all');
        expect(CTA.sambungin).toBe('Connect');
        expect(CTA.putus).toBe('Disconnect');
        expect(CTA.pasang).toBe('Equip');
        expect(CTA.lagiDipake).toBe('Equipped');
        expect(CTA.bacaUlang).toBe('Reread');
        expect(CTA.mintaTemariBacain).toBe('Ask Temari to read it');
        expect(CTA.sipMulai).toBe("Let's go");
        expect(CTA.cobaLagi).toBe('Try again');
        expect(CTA.batal).toBe('Cancel');
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
