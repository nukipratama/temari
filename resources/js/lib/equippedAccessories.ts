import type { TemariEquipped } from '@/components/temari/TemariProto';
import type { EquippedAccessories } from '@/types/inertia';

/**
 * Canonical unlock keys from config/temari_unlocks.php. Shared by the mascot's
 * accessory overlays (TemariCharacter) and the equipped→keys conversion below
 * so the two can't drift.
 */
export const ACCESSORY_KEYS = {
    headbandLegendary: 'accessory.headband_legendary',
    headbandEpic: 'accessory.headband_epic',
    headbandRare: 'accessory.headband_rare',
    headbandUncommon: 'accessory.headband_uncommon',
    medalFirst: 'accessory.medal_first',
    medalGold: 'accessory.medal_gold',
    medalSilver: 'accessory.medal_silver',
    medalPlatinum: 'accessory.medal_platinum',
    shirtBeginner: 'accessory.shirt_beginner',
    shirtEarlyBird: 'accessory.shirt_early_bird',
    shirtRainWarrior: 'accessory.shirt_rain_warrior',
    shirtLegendary: 'accessory.shirt_legendary',
    shortsLightweight: 'accessory.shorts_lightweight',
    shortsExplorer: 'accessory.shorts_explorer',
    shortsNegativeSplit: 'accessory.shorts_negative_split',
    shortsMarathon: 'accessory.shorts_marathon',
    shoesBasic: 'accessory.shoes_basic',
    shoesSpeed: 'accessory.shoes_speed',
    shoesRugged: 'accessory.shoes_rugged',
    shoesLegendary: 'accessory.shoes_legendary',
    auraWarmup: 'accessory.aura_warmup',
    auraHeatwave: 'accessory.aura_heatwave',
    auraCalm: 'accessory.aura_calm',
    auraChampion: 'accessory.aura_champion',
    auraWindrunner: 'accessory.aura_windrunner',
} as const;

/**
 * Flattens the resolved equipped set into the unlock keys the mascot overlays
 * key off — one per slot, so the mascot shows exactly what the user equipped
 * (not every accessory they've unlocked).
 */
export function equippedToKeys(
    equipped: EquippedAccessories | null | undefined,
): string[] {
    if (!equipped) {
        return [];
    }

    const keys: string[] = [];

    for (const value of Object.values(equipped)) {
        if (typeof value === 'string' && value.length > 0) {
            keys.push(value);
        }
    }

    return keys;
}

// ── Server unlock key → TemariEquipped variant mappers ─────────────
//
// Single source of truth for mapping the server-side unlock key strings
// (e.g. `accessory.headband_legendary`) to the typed TemariEquipped
// variants (e.g. `legendaris`). Shared by Temari.tsx, Aksesori.tsx, and
// AksesoriUnlockModal.tsx.
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
        headband_legendary: 'legendaris',
        headband_epic: 'epik',
        headband_rare: 'epik',
        headband_uncommon: 'ember',
    }),
    medal: variantMap<TemariEquipped['medal']>({
        medal_platinum: 'platina',
        medal_silver: 'perak',
        medal_gold: 'emas',
        medal_first: 'pertama',
    }),
    shirt: variantMap<TemariEquipped['kaus']>({
        shirt_legendary: 'legendaris',
        shirt_rain_warrior: 'hujan',
        shirt_early_bird: 'pagi',
        shirt_beginner: 'pemula',
    }),
    shorts: variantMap<TemariEquipped['celana']>({
        shorts_marathon: 'maraton',
        shorts_negative_split: 'split',
        shorts_explorer: 'jarak',
        shorts_lightweight: 'ringan',
    }),
    shoes: variantMap<TemariEquipped['sepatu']>({
        shoes_legendary: 'legendaris',
        shoes_rugged: 'tahan',
        shoes_speed: 'cepat',
        shoes_basic: 'basic',
    }),
    aura: variantMap<TemariEquipped['aura']>({
        aura_champion: 'jagoan',
        aura_calm: 'tenang',
        aura_heatwave: 'gerah',
        aura_warmup: 'pemanasan',
        aura_windrunner: 'angin',
    }),
};

/** Extract the segment after `accessory.` from a full unlock key. */
function suffixOf(key: string): string {
    const dotIndex = key.indexOf('.');
    return dotIndex === -1 ? key : key.slice(dotIndex + 1);
}

export function mapHeadband(key: string | null): TemariEquipped['headband'] {
    if (!key) return null;
    return VARIANT_MAPS.headband[suffixOf(key)] ?? 'ember';
}

export function mapMedal(key: string | null): TemariEquipped['medal'] {
    if (!key) return 'none';
    return VARIANT_MAPS.medal[suffixOf(key)] ?? 'pertama';
}

export function mapKaus(key: string | null): TemariEquipped['kaus'] {
    if (!key) return null;
    return VARIANT_MAPS.shirt[suffixOf(key)] ?? 'pemula';
}

export function mapCelana(key: string | null): TemariEquipped['celana'] {
    if (!key) return null;
    return VARIANT_MAPS.shorts[suffixOf(key)] ?? 'ringan';
}

export function mapSepatu(key: string | null): TemariEquipped['sepatu'] {
    if (!key) return null;
    return VARIANT_MAPS.shoes[suffixOf(key)] ?? 'basic';
}

export function mapAura(key: string | null): TemariEquipped['aura'] {
    if (!key) return null;
    return VARIANT_MAPS.aura[suffixOf(key)] ?? 'pemanasan';
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
        kaus: mapKaus(ea.shirt),
        celana: mapCelana(ea.shorts),
        sepatu: mapSepatu(ea.shoes),
        aura: mapAura(ea.aura),
    };
}

/**
 * Converts a single unlock key into a TemariEquipped that shows only the
 * relevant slot. Used by AksesoriUnlockModal and the Aksesori card previews.
 */
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
    shirt: (key) => mapKaus(key),
    shorts: (key) => mapCelana(key),
    shoes: (key) => mapSepatu(key),
    aura: (key) => mapAura(key),
};

/** Slots where the default is `{ medal: 'none' }` (slots other than medal are absent/null). */
const SLOT_KEYS: Record<SlotName, keyof TemariEquipped> = {
    headband: 'headband',
    medal: 'medal',
    shirt: 'kaus',
    shorts: 'celana',
    shoes: 'sepatu',
    aura: 'aura',
};

export function keyToPreviewEquipped(key: string): TemariEquipped {
    const base: TemariEquipped = { medal: 'none' };
    const suffix = suffixOf(key);

    for (const prefix of SLOT_PREFIXES) {
        if (suffix.startsWith(prefix + '_') || suffix === prefix) {
            const slotKey = SLOT_KEYS[prefix];
            return { ...base, [slotKey]: SLOT_MAPPER[prefix](key) };
        }
    }

    return { headband: 'epik' };
}
