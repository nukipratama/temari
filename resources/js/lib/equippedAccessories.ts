import type { EquippedAccessories } from '@/types/inertia';
import type { TemariEquipped } from '@/components/temari/TemariProto';

/**
 * Canonical unlock keys from config/temari_unlocks.php. Shared by the mascot's
 * accessory overlays (TemariCharacter) and the equipped→keys conversion below
 * so the two can't drift.
 */
export const ACCESSORY_KEYS = {
    ikatKepalaLegendaris: 'accessory.ikat_kepala_legendaris',
    ikatKepalaEpik: 'accessory.ikat_kepala_epik',
    ikatKepalaLangka: 'accessory.ikat_kepala_langka',
    ikatKepalaBerkesan: 'accessory.ikat_kepala_berkesan',
    medalPertama: 'accessory.medal_pertama',
    medalEmas: 'accessory.medal_emas',
    medalPerak: 'accessory.medal_perak',
    medalPlatina: 'accessory.medal_platina',
    kausPemula: 'accessory.kaus_pemula',
    kausPagi: 'accessory.kaus_pagi',
    kausHujan: 'accessory.kaus_hujan',
    kausLegendaris: 'accessory.kaus_legendaris',
    celanaRingan: 'accessory.celana_ringan',
    celanaJarak: 'accessory.celana_jarak',
    celanaSplit: 'accessory.celana_split',
    celanaMaraton: 'accessory.celana_maraton',
    sepatuBasic: 'accessory.sepatu_basic',
    sepatuCepat: 'accessory.sepatu_cepat',
    sepatuTahan: 'accessory.sepatu_tahan',
    sepatuLegendaris: 'accessory.sepatu_legendaris',
    auraPemanasan: 'accessory.aura_pemanasan',
    auraGerah: 'accessory.aura_gerah',
    auraTenang: 'accessory.aura_tenang',
    auraJagoan: 'accessory.aura_jagoan',
} as const;

/**
 * Flattens the resolved equipped set into the unlock keys the mascot overlays
 * key off — one per slot, so the mascot shows exactly what the user equipped
 * (not every accessory they've unlocked).
 */
export function equippedToKeys(equipped: EquippedAccessories | null | undefined): string[] {
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
// (e.g. `accessory.ikat_kepala_legendaris`) to the typed TemariEquipped
// variants (e.g. `legendaris`). Shared by Temari.tsx, Aksesori.tsx, and
// AksesoriUnlockModal.tsx.
//
// Keys follow the pattern `accessory.{slot}_{suffix}`. The variant is
// extracted by splitting on `.` and then looking up the last segment in a
// per-slot map, so renames or ambiguous substrings cannot cause false
// matches.

/** Key-suffix → variant for each slot. The suffix is the full segment after `accessory.`. */
const VARIANT_MAPS = {
    ikat_kepala: {
        ikat_kepala_legendaris: 'legendaris',
        ikat_kepala_epik: 'epik',
        ikat_kepala_langka: 'epik',
        ikat_kepala_berkesan: 'ember',
    } as Record<string, TemariEquipped['headband']>,
    medal: {
        medal_platina: 'platina',
        medal_perak: 'perak',
        medal_emas: 'emas',
        medal_pertama: 'pertama',
    } as Record<string, TemariEquipped['medal']>,
    kaus: {
        kaus_legendaris: 'legendaris',
        kaus_hujan: 'hujan',
        kaus_pagi: 'pagi',
        kaus_pemula: 'pemula',
    } as Record<string, TemariEquipped['kaus']>,
    celana: {
        celana_maraton: 'maraton',
        celana_split: 'split',
        celana_jarak: 'jarak',
        celana_ringan: 'ringan',
    } as Record<string, TemariEquipped['celana']>,
    sepatu: {
        sepatu_legendaris: 'legendaris',
        sepatu_tahan: 'tahan',
        sepatu_cepat: 'cepat',
        sepatu_basic: 'basic',
    } as Record<string, TemariEquipped['sepatu']>,
    aura: {
        aura_jagoan: 'jagoan',
        aura_tenang: 'tenang',
        aura_gerah: 'gerah',
        aura_pemanasan: 'pemanasan',
    } as Record<string, TemariEquipped['aura']>,
} as const;

/** Extract the segment after `accessory.` from a full unlock key. */
function suffixOf(key: string): string {
    const dotIndex = key.indexOf('.');
    return dotIndex === -1 ? key : key.slice(dotIndex + 1);
}

export function mapHeadband(key: string | null): TemariEquipped['headband'] {
    if (!key) return null;
    return VARIANT_MAPS.ikat_kepala[suffixOf(key)] ?? 'ember';
}

export function mapMedal(key: string | null): TemariEquipped['medal'] {
    if (!key) return 'none';
    return VARIANT_MAPS.medal[suffixOf(key)] ?? 'pertama';
}

export function mapKaus(key: string | null): TemariEquipped['kaus'] {
    if (!key) return null;
    return VARIANT_MAPS.kaus[suffixOf(key)] ?? 'pemula';
}

export function mapCelana(key: string | null): TemariEquipped['celana'] {
    if (!key) return null;
    return VARIANT_MAPS.celana[suffixOf(key)] ?? 'ringan';
}

export function mapSepatu(key: string | null): TemariEquipped['sepatu'] {
    if (!key) return null;
    return VARIANT_MAPS.sepatu[suffixOf(key)] ?? 'basic';
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
        headband: mapHeadband(ea.ikat_kepala),
        medal: mapMedal(ea.medal),
        kaus: mapKaus(ea.kaus),
        celana: mapCelana(ea.celana),
        sepatu: mapSepatu(ea.sepatu),
        aura: mapAura(ea.aura),
    };
}

/**
 * Converts a single unlock key into a TemariEquipped that shows only the
 * relevant slot. Used by AksesoriUnlockModal and the Aksesori card previews.
 */
/** Slot prefixes in priority order (longest first to avoid partial matches). */
const SLOT_PREFIXES = [
    'ikat_kepala',
    'medal',
    'kaus',
    'celana',
    'sepatu',
    'aura',
] as const;

type SlotName = typeof SLOT_PREFIXES[number];

const SLOT_MAPPER: Record<SlotName, (key: string) => TemariEquipped[keyof TemariEquipped]> = {
    ikat_kepala: (key) => mapHeadband(key),
    medal: (key) => mapMedal(key),
    kaus: (key) => mapKaus(key),
    celana: (key) => mapCelana(key),
    sepatu: (key) => mapSepatu(key),
    aura: (key) => mapAura(key),
};

/** Slots where the default is `{ medal: 'none' }` (slots other than medal are absent/null). */
const SLOT_KEYS: Record<SlotName, keyof TemariEquipped> = {
    ikat_kepala: 'headband',
    medal: 'medal',
    kaus: 'kaus',
    celana: 'celana',
    sepatu: 'sepatu',
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
