import { describe, expect, it } from 'vitest';
import { DAYBREAK, hrZone } from './chartTokens';

describe('DAYBREAK chart token bridge', () => {
    it('mirrors the canonical Daybreak hex values from app.css @theme', () => {
        expect(DAYBREAK.leaf).toBe('#6b8e6f');
        expect(DAYBREAK.ember).toBe('#c4623f');
        expect(DAYBREAK.mumet).toBe('#7b5bb6');
        expect(DAYBREAK.horizon).toBe('#e8a076');
        expect(DAYBREAK.citrus).toBe('#d9b23a');
    });

    it('exposes every value as a 6-digit lowercase hex', () => {
        for (const value of Object.values(DAYBREAK)) {
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
