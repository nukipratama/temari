import { formatDuration, formatDurationHMS, formatKm, formatNaiveIdDate, paceSecPerKm, formatPace } from '@/lib/pace';
import type { ActivityDetail, Rarity, ZonePct } from '@/types/inertia';
import type { TemariPose } from '@/components/temari/TemariProto';

export const RARITY_LABELS: Record<Rarity, string> = {
    common: 'Biasa',
    uncommon: 'Berkesan',
    rare: 'Langka',
    epic: 'Istimewa',
    legendary: 'Legendaris',
};

export const RARITY_ORDER: Rarity[] = ['common', 'uncommon', 'rare', 'epic', 'legendary'];

// Slug → display name (emoji emblem + casual-Jakarta name). The two English ones
// are running terms (code-switch rule), the rest are ID-first. Mirrored in PHP
// Badge::labels() — keep both runtimes in sync.
export const BADGE_LABELS: Record<string, string> = {
    hari_panas: '🔥 Tahan Gerah',
    pejuang_hujan: '🌧️ Pejuang Hujan',
    anak_pagi: '🌅 Anak Pagi',
    long_slow_distance: '🐢 Long Slow Distance',
    negative_split: '👻 Negative Split',
    tahan_diri: '🧘 Anti Kalap',
    anak_malam: '🌙 Anak Malam',
    pendaki: '⛰️ Pendaki',
    pertama_kali: '🏅 Pertama Kali',
    rajin: '💪 Rajin',
    kilat: '⚡ Kilat',
    jauh: '🗺️ Jauh',
    z2_master: '🫀 Z2 Master',
    anak_dingin: '❄️ Anak Dingin',
    keras: '😤 Keras',
    santai: '☺️ Santai',
    berturut: '🔥 Berturut',
    hari_spesial: '🎉 Hari Spesial',
    lawan_angin: '🌬️ Lawan Angin',
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
    anak_malam: 'Lari malam, sebelum subuh atau setelah jam 9.',
    pendaki: 'Elevasi total 200m ke atas, kayak naik gunung.',
    pertama_kali: 'Lari pertama yang tercatat.',
    rajin: 'Lari 3 hari berturut-turut.',
    kilat: 'Pace di bawah 5:00/km, kencang.',
    jauh: 'Jarak half marathon ke atas, 21K+.',
    z2_master: 'Lebih dari 80% waktu di Z2.',
    anak_dingin: 'Lari sebelum jam 6 pagi, still dark still cold.',
    keras: 'HR rata-rata di atas 85% max, full effort.',
    santai: 'HR rata-rata di bawah 70% max, beneran easy.',
    berturut: 'Lari 7 hari berturut-turut, tanpa skip.',
    hari_spesial: 'Lari pas hari libur nasional.',
    lawan_angin: 'Lari nembus angin kencang, 20 km/j ke atas.',
};

export const RARITY_BORDER: Record<Rarity, string> = {
    common: 'border-rarity-common',
    uncommon: 'border-rarity-uncommon',
    rare: 'border-rarity-rare',
    epic: 'border-rarity-epic',
    legendary: 'border-rarity-legendary',
};

// Escalating "set symbol" glyph per rarity (circle to star), TCG-style. Colored
// via RARITY_TEXT. Mirrored as RARITY_SYMBOL in lib/shareCard.ts for the canvas.
export const RARITY_SYMBOL: Record<Rarity, string> = {
    common: '●',
    uncommon: '◆',
    rare: '★',
    epic: '✦',
    legendary: '✺',
};

// Loot-ladder rarity hex — mirrors the --color-rarity-* tokens in app.css.
// Lives here so JS/SVG/canvas (RouteGlyph, Kartu CSS var, shareCard) all share
// one source where a CSS var can't reach (inline SVG fill, canvas fillStyle).
export const RARITY_HEX: Record<Rarity, string> = {
    common: '#7d8694',
    uncommon: '#2fb350',
    rare: '#2f81f7',
    epic: '#a855f7',
    legendary: '#f5a623',
};

export const RARITY_TEXT: Record<Rarity, string> = {
    common: 'text-rarity-common',
    uncommon: 'text-rarity-uncommon',
    rare: 'text-rarity-rare',
    epic: 'text-rarity-epic',
    legendary: 'text-rarity-legendary',
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

// Headband color driven by rarity — wired to TemariProto's `equipped.headband`.
export const RARITY_HEADBAND: Record<Rarity, 'ember' | 'epik' | 'legendaris'> = {
    common: 'ember',
    uncommon: 'ember',
    rare: 'epik',
    epic: 'legendaris',
    legendary: 'legendaris',
};

// Mascot pose driven by rarity — reinforces the tier hierarchy on cards and detail page.
export const RARITY_POSE: Record<Rarity, TemariPose> = {
    common: 'observational',
    uncommon: 'proud',
    rare: 'excited',
    epic: 'pumped',
    legendary: 'glow',
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

// Mean per-km cadence (spm) from stream_summary, rounded. Null when no cadence
// data exists on any split.
export function avgCadenceFromDetail(detail?: ActivityDetail | null): number | null {
    const perKm = detail?.stream_summary?.per_km;
    if (!perKm?.length) return null;
    const cadences = perKm
        .map((split) => split.avg_cadence_spm)
        .filter((spm): spm is number => spm != null && spm > 0);
    if (cadences.length === 0) return null;
    return Math.round(cadences.reduce((sum, spm) => sum + spm, 0) / cadences.length);
}

// The fastest single km as its "M:SS" pace string. Null when no per-km data.
export function fastestKmFromDetail(detail?: ActivityDetail | null): string | null {
    const perKm = detail?.stream_summary?.per_km;
    if (!perKm?.length) return null;
    let best: { pace: string; seconds: number } | null = null;
    for (const split of perKm) {
        const seconds = parsePaceSeconds(split.pace);
        if (seconds !== null && (best === null || seconds < best.seconds)) {
            best = { pace: split.pace, seconds };
        }
    }
    return best?.pace ?? null;
}

// HR zone distribution (% per Z1..Z5) from stream_summary. Null when the run
// has no zone data (e.g. no HR), so callers can hide the zone bar.
export function zonePctFromDetail(detail?: ActivityDetail | null): ZonePct | null {
    const zones = detail?.stream_summary?.time_in_zone_pct;
    if (zones == null) return null;
    const hasData = (['Z1', 'Z2', 'Z3', 'Z4', 'Z5'] as const).some((z) => (zones[z] ?? 0) > 0);
    return hasData ? zones : null;
}

/** The display-formatted secondary stats a `Kartu` shows (assignable to KartuStats). */
export interface CardStatStrings {
    pace?: string;
    hr?: string;
    cadence?: string;
    fastestKm?: string;
    elevation?: string;
}

/**
 * Derive a card's display stats (pace · HR · cadence · fastest km) from a run's
 * detail, in one place — every `<Kartu stats={...}>` call site feeds from this so
 * the `${x} bpm` / `${x} spm` / `${pace}/km` formatting can't drift. Each value is
 * omitted (not "—") when its source is missing, matching the card's honest-cells rule.
 */
export function buildCardStats(detail?: ActivityDetail | null): CardStatStrings {
    const paceSec = paceSecPerKm(detail?.moving_time, detail?.distance);
    const cadence = avgCadenceFromDetail(detail);
    const fastestKm = fastestKmFromDetail(detail);
    return {
        pace: paceSec != null ? `${formatPace(paceSec)}/km` : undefined,
        hr: detail?.average_heartrate != null ? `${Math.round(detail.average_heartrate)} bpm` : undefined,
        cadence: cadence != null ? `${cadence} spm` : undefined,
        fastestKm: fastestKm != null ? `${fastestKm}/km` : undefined,
        elevation: detail?.total_elevation_gain != null ? `${Math.round(detail.total_elevation_gain)} m` : undefined,
    };
}

/** The shared `<Kartu>` prop bag derived from a run's detail. */
export interface KartuPropsFromDetail {
    km: string;
    durasi: string;
    trimp: string;
    subtitle: string | null;
    stats: CardStatStrings;
    zonePct: ZonePct | null;
    paceShape: number[];
}

export interface KartuPropsOptions {
    /**
     * Duration display: `'hms'` (digital "30:10", default) or `'words'`
     * ("30 menit 10 detik"). Cards use HMS so the fixed-width stat-grid cell
     * doesn't clip the long words form under `truncate`.
     */
    durationFormat?: 'words' | 'hms';
}

/**
 * Derive the `km/durasi/trimp/subtitle/stats/zonePct/paceShape` prop bag a
 * `<Kartu>` renders from a run's detail, in one place. Every `<Kartu>` call site
 * feeds from this so the `… != null ? … : '—'` sentinels can't drift. `subtitle`
 * is `null` when detail is absent (callers that always have a detail get the
 * built string). `trimp` is a string (Kartu accepts `string | number`).
 */
export function kartuPropsFromDetail(
    detail?: ActivityDetail | null,
    { durationFormat = 'hms' }: KartuPropsOptions = {},
): KartuPropsFromDetail {
    const durasi =
        detail?.moving_time == null
            ? '—'
            : durationFormat === 'hms'
              ? formatDurationHMS(detail.moving_time)
              : formatDuration(detail.moving_time);
    return {
        km: formatKm(detail?.distance),
        durasi,
        trimp: detail?.trimp_edwards == null ? '—' : String(Math.round(detail.trimp_edwards)),
        subtitle: detail ? `${detail.name ?? 'Lari'} · ${formatNaiveIdDate(detail.start_date_local, 'short')}` : null,
        stats: buildCardStats(detail),
        zonePct: zonePctFromDetail(detail),
        paceShape: paceShapeFromDetail(detail),
    };
}
