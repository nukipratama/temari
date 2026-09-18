import { describe, expect, it } from 'vitest';

import { CREAM, INK, RARITY_INK_HEX, readableInk } from '@/lib/card/palette';

describe('card export palette', () => {
    it('puts dark ink on a light fill and cream on a dark one', () => {
        expect(readableInk('#f5a623')).toBe(INK);
        expect(readableInk('#2463be')).toBe(CREAM);
        expect(readableInk(CREAM)).toBe(INK);
    });

    it('carries an ink tier for every rarity, since a print has no ground', () => {
        expect(Object.values(RARITY_INK_HEX)).toHaveLength(5);
        for (const hex of Object.values(RARITY_INK_HEX)) {
            expect(hex).toMatch(/^#[0-9a-f]{6}$/);
        }
    });
});
