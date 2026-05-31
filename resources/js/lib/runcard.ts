import type { ActivityDetail, Rarity } from '@/types/inertia';

export const RARITY_LABELS: Record<Rarity, string> = {
    common: 'Biasa',
    uncommon: 'Berkesan',
    rare: 'Langka',
    epic: 'Luar Biasa',
    legendary: 'Legendaris',
};

export const RARITY_ORDER: Rarity[] = ['common', 'uncommon', 'rare', 'epic', 'legendary'];

// Slug → display name (emoji emblem + casual-Jakarta name). The two English ones
// are running terms (code-switch rule), the rest are ID-first. Mirrored in PHP
// RunCard::BADGE_LABELS — keep both runtimes in sync.
export const BADGE_LABELS: Record<string, string> = {
    hari_panas: '🔥 Tahan Gerah',
    pejuang_hujan: '🌧️ Pejuang Hujan',
    anak_pagi: '🌅 Anak Pagi',
    long_slow_distance: '🐢 Long Slow Distance',
    negative_split: '👻 Negative Split',
    tahan_diri: '🧘 Anti Kalap',
};

// One-line "ability" meaning per badge, accurate to RunCardFactory::badges()
// thresholds. Casual register, no em-dashes. Shown on the card ability rows.
export const BADGE_ABILITY: Record<string, string> = {
    hari_panas: 'Nekat lari pas gerah 31°C ke atas.',
    pejuang_hujan: 'Diguyur hujan tetap lanjut lari.',
    anak_pagi: 'Udah lari sebelum jam 6 pagi.',
    long_slow_distance: 'Jarak jauh 12K+ santai, mayoritas pelan.',
    negative_split: 'Paruh kedua malah lebih ngebut.',
    tahan_diri: '10K+ sabar, gak kepancing buat ngebut.',
};

export const RARITY_BORDER: Record<Rarity, string> = {
    common: 'border-rarity-common',
    uncommon: 'border-rarity-uncommon',
    rare: 'border-rarity-rare',
    epic: 'border-rarity-epic',
    legendary: 'border-rarity-legendary',
};

// Static literal Tailwind class maps (so JIT picks them up) for the rarity
// swatch + surface wash shared by the card and the mini tile.
export const RARITY_DOT: Record<Rarity, string> = {
    common: 'bg-rarity-common',
    uncommon: 'bg-rarity-uncommon',
    rare: 'bg-rarity-rare',
    epic: 'bg-rarity-epic',
    legendary: 'bg-rarity-legendary',
};

export const RARITY_TINT: Record<Rarity, string> = {
    common: 'bg-rarity-common/[0.05]',
    uncommon: 'bg-rarity-uncommon/[0.06]',
    rare: 'bg-rarity-rare/[0.07]',
    epic: 'bg-rarity-epic/[0.08]',
    legendary: 'bg-rarity-legendary/[0.09]',
};

// Slug → Title Case ("anak_pagi" → "Anak Pagi"). Fallback for unknown slugs.
export function prettyBadge(slug: string): string {
    return slug
        .split('_')
        .map((p) => p.charAt(0).toUpperCase() + p.slice(1))
        .join(' ');
}

// Emoji emblem for a badge slug ("hari_panas" → "🔥"). Empty when unknown.
export function badgeEmblem(slug: string): string {
    const label = BADGE_LABELS[slug];
    if (!label) return '';
    const sp = label.indexOf(' ');
    return sp === -1 ? '' : label.slice(0, sp);
}

// Display name without the leading emoji ("hari_panas" → "Tahan Gerah").
// Falls back to prettyBadge for slugs not in BADGE_LABELS.
export function badgeName(slug: string): string {
    const label = BADGE_LABELS[slug];
    if (!label) return prettyBadge(slug);
    const sp = label.indexOf(' ');
    return sp === -1 ? label : label.slice(sp + 1);
}

// Parse a "M:SS" pace string to seconds. Null on malformed input.
function parsePaceSeconds(mmss: string): number | null {
    const match = /^(\d+):(\d{2})$/.exec(mmss.trim());
    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

// Per-km pace seconds from stream_summary, for the RouteGlyph pace-shape
// fallback when a run has no GPS polyline. Empty when no per-km data exists.
export function paceShapeFromDetail(detail?: ActivityDetail | null): number[] {
    const perKm = detail?.stream_summary?.per_km;
    if (!perKm?.length) return [];
    return perKm
        .map((split) => parsePaceSeconds(split.pace))
        .filter((seconds): seconds is number => seconds !== null);
}
