import type { ActivityDetail, Rarity, ZonePct } from '@/types/inertia';

import {
    formatDuration,
    formatDurationHMS,
    formatKm,
    formatNaiveIdDate,
    paceSecPerKm,
    formatPace,
} from '@/lib/pace';

// Mirrored word-for-word from App\Enums\Rarity::label(); types/generated.ts
// only emits RARITY_VALUES, not labels.
export const RARITY_LABELS: Record<Rarity, string> = {
    common: 'Common',
    uncommon: 'Uncommon',
    rare: 'Rare',
    epic: 'Epic',
    legendary: 'Legendary',
};

export const RARITY_ORDER: Rarity[] = [
    'common',
    'uncommon',
    'rare',
    'epic',
    'legendary',
];

// Escalating "set symbol" glyph per rarity (circle to star), TCG-style. Colored
// via RARITY_TEXT.
export const RARITY_SYMBOL: Record<Rarity, string> = {
    common: '●',
    uncommon: '◆',
    rare: '★',
    epic: '✦',
    legendary: '✺',
};

// Loot-ladder rarity hex — mirrors the --color-rarity-* tokens in app.css.
// Lives here so every JS/SVG reader (RouteGlyph, the Card CSS var) shares one
// source where a CSS var can't reach (an inline SVG fill attribute).
export const RARITY_HEX: Record<Rarity, string> = {
    common: '#7d8694',
    uncommon: '#2fb350',
    rare: '#2f81f7',
    epic: '#a855f7',
    legendary: '#f5a623',
};

// Thread-band accent density (Slice 9c).
// Additive rarity chrome, not a re-hue: more stitches at higher tiers.
export const RARITY_BAND_COUNT: Record<Rarity, number> = {
    common: 1,
    uncommon: 2,
    rare: 3,
    epic: 4,
    legendary: 5,
};

/** A single thread-band stitch, normalized to a 0..1 x 0..1 unit box. */
export interface ThreadBandLine {
    x1: number;
    y1: number;
    x2: number;
    y2: number;
    opacity: number;
}

// Hand-placed stitch positions per count rather than an evenly-divided loop,
// so 1-3 stitches read as a deliberate, balanced cluster instead of bunching
// at one edge. From 4 on, a second set leans the opposite way and crosses
// the rest — the "elaborate interwoven" look the top two tiers get.
const THREAD_BAND_PRIMARY_X: Record<number, number[]> = {
    1: [0.5],
    2: [0.32, 0.68],
    3: [0.18, 0.5, 0.82],
};
const THREAD_BAND_CROSS_X: Record<number, number[]> = {
    1: [0.36],
    2: [0.22, 0.6],
};

/**
 * Thread-band stitch geometry for a tier's band count (1-5). Shared by the
 * React card glyph ({@see ThreadBandGlyph}) and the canvas share-card
 * renderer so both draw the identical pattern from one source.
 */
export function threadBandLines(count: number): ThreadBandLine[] {
    const primaryCount = Math.min(count, 3);
    const crossCount = Math.max(count - 3, 0);
    const lean = 0.09;
    const lines: ThreadBandLine[] = [];
    for (const x of THREAD_BAND_PRIMARY_X[primaryCount] ?? []) {
        lines.push({ x1: x - lean, y1: 1, x2: x + lean, y2: 0, opacity: 0.95 });
    }
    for (const x of THREAD_BAND_CROSS_X[crossCount] ?? []) {
        lines.push({ x1: x - lean, y1: 0, x2: x + lean, y2: 1, opacity: 0.6 });
    }
    return lines;
}

// The vivid fill as a text colour. Only legible on the card's dark frame —
// on paper use RARITY_INK.
export const RARITY_TEXT: Record<Rarity, string> = {
    common: 'text-rarity-common',
    uncommon: 'text-rarity-uncommon',
    rare: 'text-rarity-rare',
    epic: 'text-rarity-epic',
    legendary: 'text-rarity-legendary',
};

// The only rarity colours allowed to carry text or an icon on paper.
export const RARITY_INK: Record<Rarity, string> = {
    common: 'text-rarity-common-ink',
    uncommon: 'text-rarity-uncommon-ink',
    rare: 'text-rarity-rare-ink',
    epic: 'text-rarity-epic-ink',
    legendary: 'text-rarity-legendary-ink',
};

// Parse a "M:SS" pace string to seconds. Null on malformed input.
function parsePaceSeconds(mmss: string): number | null {
    const match = /^(\d+):(\d{2})$/.exec(mmss.trim());
    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

// Per-km pace seconds from stream_summary, for the RouteGlyph pace-shape
// fallback when a run has no GPS polyline. Empty when no per-km data exists.
function paceShapeFromDetail(detail?: ActivityDetail | null): number[] {
    const perKm = detail?.stream_summary?.per_km;
    if (!perKm?.length) return [];
    return perKm
        .map((split) => parsePaceSeconds(split.pace))
        .filter((seconds): seconds is number => seconds !== null);
}

// Mean per-km cadence (spm) from stream_summary, rounded. Null when no cadence
// data exists on any split.
export function avgCadenceFromDetail(
    detail?: ActivityDetail | null,
): number | null {
    const perKm = detail?.stream_summary?.per_km;
    if (!perKm?.length) return null;
    const cadences = perKm
        .map((split) => split.avg_cadence_spm)
        .filter((spm): spm is number => spm != null && spm > 0);
    if (cadences.length === 0) return null;
    return Math.round(
        cadences.reduce((sum, spm) => sum + spm, 0) / cadences.length,
    );
}

// The fastest single km as its "M:SS" pace string. Null when no per-km data.
export function fastestKmFromDetail(
    detail?: ActivityDetail | null,
): string | null {
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
export function zonePctFromDetail(
    detail?: ActivityDetail | null,
): ZonePct | null {
    const zones = detail?.stream_summary?.time_in_zone_pct;
    if (zones == null) return null;
    const hasData = (['Z1', 'Z2', 'Z3', 'Z4', 'Z5'] as const).some(
        (z) => (zones[z] ?? 0) > 0,
    );
    return hasData ? zones : null;
}

/** The display-formatted secondary stats a `Card` shows (assignable to CardStats). */
export interface CardStatStrings {
    pace?: string;
    hr?: string;
    cadence?: string;
    fastestKm?: string;
    elevation?: string;
}

/**
 * Derive a card's display stats (pace · HR · cadence · fastest km) from a run's
 * detail, in one place — every `<Card stats={...}>` call site feeds from this so
 * the `${x} bpm` / `${x} spm` / `${pace}/km` formatting can't drift. Each value is
 * omitted (not "—") when its source is missing, matching the card's honest-cells rule.
 */
function buildCardStats(detail?: ActivityDetail | null): CardStatStrings {
    const paceSec = paceSecPerKm(detail?.elapsed_time, detail?.distance);
    const cadence = avgCadenceFromDetail(detail);
    const fastestKm = fastestKmFromDetail(detail);
    return {
        pace: paceSec != null ? `${formatPace(paceSec)}/km` : undefined,
        hr:
            detail?.average_heartrate != null
                ? `${Math.round(detail.average_heartrate)} bpm`
                : undefined,
        cadence: cadence != null ? `${cadence} spm` : undefined,
        fastestKm: fastestKm != null ? `${fastestKm}/km` : undefined,
        elevation:
            detail?.total_elevation_gain != null
                ? `${Math.round(detail.total_elevation_gain)} m`
                : undefined,
    };
}

/** The shared `<Card>` prop bag derived from a run's detail. */
export interface CardPropsFromDetail {
    km: string;
    duration: string;
    trimp: string;
    subtitle: string | null;
    stats: CardStatStrings;
    zonePct: ZonePct | null;
    paceShape: number[];
}

export interface CardPropsOptions {
    /**
     * Duration display: `'hms'` (digital "30:10", default) or `'words'`
     * ("30 min 10 sec"). Cards use HMS so the fixed-width stat-grid cell
     * doesn't clip the long words form under `truncate`.
     */
    durationFormat?: 'words' | 'hms';
}

/**
 * Derive the `km/duration/trimp/subtitle/stats/zonePct/paceShape` prop bag a
 * `<Card>` renders from a run's detail, in one place. Every `<Card>` call site
 * feeds from this so the `… != null ? … : '—'` sentinels can't drift. `subtitle`
 * is `null` when detail is absent (callers that always have a detail get the
 * built string). `trimp` is a string (Card accepts `string | number`).
 */
export function cardPropsFromDetail(
    detail?: ActivityDetail | null,
    { durationFormat = 'hms' }: CardPropsOptions = {},
): CardPropsFromDetail {
    const duration =
        detail?.elapsed_time == null
            ? '—'
            : durationFormat === 'hms'
              ? formatDurationHMS(detail.elapsed_time)
              : formatDuration(detail.elapsed_time);
    return {
        km: formatKm(detail?.distance),
        duration,
        trimp:
            detail?.trimp_edwards == null
                ? '—'
                : String(Math.round(detail.trimp_edwards)),
        subtitle: detail
            ? `${detail.name ?? 'Run'} · ${formatNaiveIdDate(detail.start_date_local, 'short')}`
            : null,
        stats: buildCardStats(detail),
        zonePct: zonePctFromDetail(detail),
        paceShape: paceShapeFromDetail(detail),
    };
}
