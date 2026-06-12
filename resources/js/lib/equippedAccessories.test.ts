import { describe, expect, it } from 'vitest';
import { ACCESSORY_KEYS, equippedToKeys } from './equippedAccessories';
import type { EquippedAccessories } from '@/types/inertia';

const emptyEquipped: EquippedAccessories = {
    medal: null,
    ikat_kepala: null,
    kaus: null,
    celana: null,
    sepatu: null,
    aura: null,
};

describe('ACCESSORY_KEYS', () => {
    it('contains all 24 unlock keys', () => {
        const keys = Object.values(ACCESSORY_KEYS);
        expect(keys).toHaveLength(24);
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
            ikat_kepala: ACCESSORY_KEYS.ikatKepalaLegendaris,
            medal: ACCESSORY_KEYS.medalEmas,
        });
        expect(result).toContain(ACCESSORY_KEYS.ikatKepalaLegendaris);
        expect(result).toContain(ACCESSORY_KEYS.medalEmas);
        expect(result).toHaveLength(2);
    });

    it('returns only the equipped keys, skipping null slots', () => {
        expect(
            equippedToKeys({
                ...emptyEquipped,
                sepatu: ACCESSORY_KEYS.sepatuBasic,
            }),
        ).toEqual([ACCESSORY_KEYS.sepatuBasic]);
    });
});
