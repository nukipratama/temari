import { describe, expect, it } from 'vitest';

import { THREADWORK, hrZone } from './chartTokens';

describe('THREADWORK chart token bridge', () => {
    it('mirrors the canonical Threadwork hex values from app.css @theme', () => {
        expect(THREADWORK.leaf).toBe('#6b8e6f');
        expect(THREADWORK.ember).toBe('#c4623f');
        expect(THREADWORK.overloaded).toBe('#6b3fa0');
        expect(THREADWORK.horizon).toBe('#d9a53c');
        expect(THREADWORK.citrus).toBe('#d9b23a');
    });

    it('exposes every value as a 6-digit lowercase hex', () => {
        for (const value of Object.values(THREADWORK)) {
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
