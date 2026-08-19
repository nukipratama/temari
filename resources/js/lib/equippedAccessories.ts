import type { TemariEquipped } from '@/components/temari/TemariProto';
import type { EquippedAccessories } from '@/types/inertia';

// ── Server unlock key → TemariEquipped variant mappers ─────────────
//
// Single source of truth for mapping the server-side unlock key strings
// (e.g. `accessory.headband_legendary`) to the typed TemariEquipped
// variants (e.g. `legendary`). Shared by Temari.tsx, Collection/Accessories.tsx
// and AccessoryUnlockModal.tsx.
//
// Keys follow the pattern `accessory.{slot}_{suffix}`. The variant is
// extracted by splitting on `.` and then looking up the last segment in a
// per-slot map, so renames or ambiguous substrings cannot cause false
// matches.

/** Builds a typed key→variant lookup without an `as Record<...>` cast at each entry. */
function variantMap<V>(map: Record<string, V>): Record<string, V> {
    return map;
}

/** Key-suffix → variant for each slot. The suffix is the full segment after `accessory.`. */
const VARIANT_MAPS = {
    headband: variantMap<TemariEquipped['headband']>({
        headband_legendary: 'legendary',
        headband_epic: 'epic',
        headband_rare: 'rare',
        headband_uncommon: 'uncommon',
    }),
    medal: variantMap<TemariEquipped['medal']>({
        medal_platinum: 'platinum',
        medal_silver: 'silver',
        medal_gold: 'gold',
        medal_first: 'first',
    }),
    shirt: variantMap<TemariEquipped['shirt']>({
        shirt_legendary: 'legendary',
        shirt_rain_warrior: 'rainWarrior',
        shirt_early_bird: 'earlyBird',
        shirt_beginner: 'beginner',
    }),
    shorts: variantMap<TemariEquipped['shorts']>({
        shorts_marathon: 'marathon',
        shorts_negative_split: 'negativeSplit',
        shorts_explorer: 'explorer',
        shorts_lightweight: 'lightweight',
    }),
    shoes: variantMap<TemariEquipped['shoes']>({
        shoes_legendary: 'legendary',
        shoes_rugged: 'rugged',
        shoes_speed: 'speed',
        shoes_basic: 'basic',
    }),
    aura: variantMap<TemariEquipped['aura']>({
        aura_champion: 'champion',
        aura_calm: 'calm',
        aura_heatwave: 'heatwave',
        aura_warmup: 'warmup',
        aura_windrunner: 'windrunner',
    }),
};

/** Extract the segment after `accessory.` from a full unlock key. */
function suffixOf(key: string): string {
    const dotIndex = key.indexOf('.');
    return dotIndex === -1 ? key : key.slice(dotIndex + 1);
}

export function mapHeadband(key: string | null): TemariEquipped['headband'] {
    if (!key) return null;
    return VARIANT_MAPS.headband[suffixOf(key)] ?? 'uncommon';
}

export function mapMedal(key: string | null): TemariEquipped['medal'] {
    if (!key) return 'none';
    return VARIANT_MAPS.medal[suffixOf(key)] ?? 'first';
}

export function mapShirt(key: string | null): TemariEquipped['shirt'] {
    if (!key) return null;
    return VARIANT_MAPS.shirt[suffixOf(key)] ?? 'beginner';
}

export function mapShorts(key: string | null): TemariEquipped['shorts'] {
    if (!key) return null;
    return VARIANT_MAPS.shorts[suffixOf(key)] ?? 'lightweight';
}

export function mapShoes(key: string | null): TemariEquipped['shoes'] {
    if (!key) return null;
    return VARIANT_MAPS.shoes[suffixOf(key)] ?? 'basic';
}

export function mapAura(key: string | null): TemariEquipped['aura'] {
    if (!key) return null;
    return VARIANT_MAPS.aura[suffixOf(key)] ?? 'warmup';
}

/**
 * Converts the full server-side EquippedAccessories payload into a
 * TemariEquipped object for the mascot component. Single call site for
 * the Temari.tsx wrapper.
 */
export function serverToEquipped(ea: EquippedAccessories): TemariEquipped {
    return {
        headband: mapHeadband(ea.headband),
        medal: mapMedal(ea.medal),
        shirt: mapShirt(ea.shirt),
        shorts: mapShorts(ea.shorts),
        shoes: mapShoes(ea.shoes),
        aura: mapAura(ea.aura),
    };
}

/** Slot prefixes in priority order (longest first to avoid partial matches). */
const SLOT_PREFIXES = [
    'headband',
    'medal',
    'shirt',
    'shorts',
    'shoes',
    'aura',
] as const;

type SlotName = (typeof SLOT_PREFIXES)[number];

const SLOT_MAPPER: Record<
    SlotName,
    (key: string) => TemariEquipped[keyof TemariEquipped]
> = {
    headband: (key) => mapHeadband(key),
    medal: (key) => mapMedal(key),
    shirt: (key) => mapShirt(key),
    shorts: (key) => mapShorts(key),
    shoes: (key) => mapShoes(key),
    aura: (key) => mapAura(key),
};

/**
 * Converts a single unlock key into a TemariEquipped that shows only the
 * relevant slot. Used by AccessoryUnlockModal and the accessory card previews.
 */
export function keyToPreviewEquipped(key: string): TemariEquipped {
    const base: TemariEquipped = { medal: 'none' };
    const suffix = suffixOf(key);

    for (const prefix of SLOT_PREFIXES) {
        if (suffix.startsWith(prefix + '_') || suffix === prefix) {
            return { ...base, [prefix]: SLOT_MAPPER[prefix](key) };
        }
    }

    return { headband: 'epic' };
}
