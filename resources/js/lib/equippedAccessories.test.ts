import { describe, expect, it } from 'vitest';

import type { EquippedAccessories } from '@/types/inertia';

import { ACCESSORY_KEYS, equippedToKeys } from './equippedAccessories';

const emptyEquipped: EquippedAccessories = {
    medal: null,
    headband: null,
    shirt: null,
    shorts: null,
    shoes: null,
    aura: null,
};

describe('ACCESSORY_KEYS', () => {
    it('contains all 25 unlock keys', () => {
        const keys = Object.values(ACCESSORY_KEYS);
        expect(keys).toHaveLength(25);
    });
});

describe('equippedToKeys', () => {
    it('returns no keys for null/empty equipped sets', () => {
        expect(equippedToKeys(null)).toEqual([]);
        expect(equippedToKeys(undefined)).toEqual([]);
        expect(equippedToKeys(emptyEquipped)).toEqual([]);
    });

    it('maps each equipped slot to its unlock key', () => {
        const result = equippedToKeys({
            ...emptyEquipped,
            headband: ACCESSORY_KEYS.headbandLegendary,
            medal: ACCESSORY_KEYS.medalGold,
        });
        expect(result).toContain(ACCESSORY_KEYS.headbandLegendary);
        expect(result).toContain(ACCESSORY_KEYS.medalGold);
        expect(result).toHaveLength(2);
    });

    it('returns only the equipped keys, skipping null slots', () => {
        expect(
            equippedToKeys({
                ...emptyEquipped,
                shoes: ACCESSORY_KEYS.shoesBasic,
            }),
        ).toEqual([ACCESSORY_KEYS.shoesBasic]);
    });
});
