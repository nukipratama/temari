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
        expect(MOOD_EMOJI.blazing).toBe('🔥');
        expect(MOOD_EMOJI.easy).toBe('🌸');
        expect(MOOD_EMOJI.wobbly).toBe('⚡');
        expect(MOOD_EMOJI.gassed).toBe('💧');
        expect(MOOD_EMOJI.overloaded).toBe('🌀');
        expect(MOOD_EMOJI.chill).toBe('🍃');
    });

    it('covers every expected mood key', () => {
        expect(Object.keys(MOOD_EMOJI).sort()).toEqual(
            [
                'chill',
                'easy',
                'gassed',
                'overloaded',
                'blazing',
                'wobbly',
            ].sort(),
        );
    });
});
