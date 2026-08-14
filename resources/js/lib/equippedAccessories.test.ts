import { describe, expect, it } from 'vitest';

import type { EquippedAccessories } from '@/types/inertia';

import { keyToPreviewEquipped, serverToEquipped } from './equippedAccessories';

const emptyEquipped: EquippedAccessories = {
    medal: null,
    headband: null,
    shirt: null,
    shorts: null,
    shoes: null,
    aura: null,
};

describe('serverToEquipped', () => {
    it('leaves every wearable slot empty when nothing is equipped', () => {
        // `medal` is the one slot with a drawn empty state ('none'); the rest
        // are simply not rendered.
        expect(serverToEquipped(emptyEquipped)).toEqual({
            headband: null,
            medal: 'none',
            shirt: null,
            shorts: null,
            shoes: null,
            aura: null,
        });
    });

    it('maps an unlock key to the variant the mascot draws', () => {
        const equipped = serverToEquipped({
            ...emptyEquipped,
            medal: 'accessory.medal_gold',
            shoes: 'accessory.shoes_basic',
        });
        expect(equipped.medal).toBe('gold');
        expect(equipped.shoes).toBe('basic');
    });

    it('falls back to the slot default for a key with no variant', () => {
        expect(
            serverToEquipped({ ...emptyEquipped, medal: 'medal_unheard_of' })
                .medal,
        ).toBe('first');
    });
});

describe('keyToPreviewEquipped', () => {
    it('equips exactly the slot the key belongs to, leaving the rest bare', () => {
        const preview = keyToPreviewEquipped('accessory.shirt_legendary');
        expect(preview.shirt).toBe('legendary');
        expect(preview.medal).toBe('none');
        expect(preview.shoes).toBeUndefined();
    });

    it('shows a headband for a key that matches no slot prefix', () => {
        expect(keyToPreviewEquipped('accessory.mystery')).toEqual({
            headband: 'epic',
        });
    });
});
