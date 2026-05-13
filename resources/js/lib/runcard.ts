import type { Rarity } from '@/types/inertia';

/**
 * Mirrors App\Models\RunCard::RARITY_LABELS and BADGE_LABELS so the FE
 * has one place to look up labels (otherwise we'd hardcode them in
 * every component that touches RunCard).
 */

export const RARITY_LABELS: Record<Rarity, string> = {
    biasa: 'Biasa',
    jarang: 'Jarang',
    langka: 'Langka',
    epik: 'Epik',
    legendaris: 'Legendaris',
};

export const RARITY_ORDER: Rarity[] = ['biasa', 'jarang', 'langka', 'epik', 'legendaris'];

export const BADGE_LABELS: Record<string, string> = {
    hari_panas: '🔥 Hari Panas',
    pejuang_hujan: '🌧️ Pejuang Hujan',
    anak_pagi: '🌅 Anak Pagi',
    long_slow_distance: '🐢 Long Slow Distance',
    negative_split: '👻 Negative Split',
    tahan_diri: '🧘 Tahan Diri',
};
