import type { Rarity } from '@/types/inertia';

export const RARITY_LABELS: Record<Rarity, string> = {
    common: 'Biasa',
    uncommon: 'Jarang',
    rare: 'Langka',
    epic: 'Epik',
    legendary: 'Legendaris',
};

export const RARITY_ORDER: Rarity[] = ['common', 'uncommon', 'rare', 'epic', 'legendary'];

export const BADGE_LABELS: Record<string, string> = {
    hari_panas: '🔥 Heat Beater',
    pejuang_hujan: '🌧️ Rainmaker',
    anak_pagi: '🌅 Early Bird',
    long_slow_distance: '🐢 Long Haul',
    negative_split: '👻 Negative Split',
    tahan_diri: '🧘 Hold Back',
};

export const RARITY_BORDER: Record<Rarity, string> = {
    common: 'border-rarity-common',
    uncommon: 'border-rarity-uncommon',
    rare: 'border-rarity-rare',
    epic: 'border-rarity-epic',
    legendary: 'border-rarity-legendary',
};

// Slug → Title Case ("anak_pagi" → "Anak Pagi"). Used for chip labels on cards.
export function prettyBadge(slug: string): string {
    return slug
        .split('_')
        .map((p) => p.charAt(0).toUpperCase() + p.slice(1))
        .join(' ');
}
