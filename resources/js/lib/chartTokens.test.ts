import { describe, expect, it } from 'vitest';

import { PALETTE, hrZone } from './chartTokens';

describe('PALETTE chart token bridge', () => {
    it('mirrors the canonical hex values from app.css @theme', () => {
        expect(PALETTE.leaf).toBe('#2f8f63');
        expect(PALETTE.ember).toBe('#b23a4f');
        expect(PALETTE.overloaded).toBe('#6b3fa0');
        expect(PALETTE.horizon).toBe('#ade047');
        expect(PALETTE.citrus).toBe('#c9971f');
        expect(PALETTE.cream).toBe('#f1f5f8');
        expect(PALETTE.ink2).toBe('#34373c');
        expect(PALETTE.ink3).toBe('#60666d');
    });

    it('exposes every value as a 6-digit lowercase hex', () => {
        for (const value of Object.values(PALETTE)) {
            expect(value).toMatch(/^#[0-9a-f]{6}$/);
        }
    });
});

describe('hrZone map', () => {
    it('covers all five HR zones', () => {
        expect(Object.keys(hrZone)).toEqual(['Z1', 'Z2', 'Z3', 'Z4', 'Z5']);
    });

    it('ramps cool teal (recovery) → warm red (max) as named hex', () => {
        expect(hrZone.Z1).toBe('#35c6da'); // bright cool teal: barely working
        expect(hrZone.Z5).toBe('#b8302f'); // red: maxed
        for (const value of Object.values(hrZone)) {
            expect(value).toMatch(/^#[0-9a-f]{6}$/);
        }
    });
});
