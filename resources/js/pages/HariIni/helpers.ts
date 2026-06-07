import { moodFromActivity } from '@/lib/moodFromActivity';
import { formatDuration, formatKm, formatRelativeId, formatShortWeekdayDateId } from '@/lib/pace';
import { RARITY_LABELS, buildCardStats, paceShapeFromDetail, zonePctFromDetail, type CardStatStrings } from '@/lib/runcard';
import type { ActivityDetail, Mood, Rarity, RunCard, ZonePct } from '@/types/inertia';

export interface FeaturedCard {
    cardId: number;
    name: string;
    subtitle: string;
    km: string;
    durasi: string;
    trimp: string;
    rarity: Rarity;
    mood: Mood;
    badges: ReadonlyArray<string>;
    stats: CardStatStrings;
    zonePct: ZonePct | null;
    polyline: string | null;
    paceShape: number[];
    startDate: string | null;
}

export interface StripItem {
    key: string;
    cardId: number;
    name: string;
    rarity: Rarity;
    date: string;
    polyline: string | null;
}

const RARITY_RANK: Record<Rarity, number> = {
    common: 0,
    uncommon: 1,
    rare: 2,
    epic: 3,
    legendary: 4,
};

function toFeaturedCard(r: ActivityDetail, card: RunCard): FeaturedCard {
    return {
        cardId: card.id,
        name: card.special_move,
        subtitle: `${RARITY_LABELS[card.rarity]} · ${formatRelativeId(r.start_date_local)}`,
        km: formatKm(r.distance),
        durasi: r.moving_time != null ? formatDuration(r.moving_time) : '—',
        trimp: r.trimp_edwards != null ? String(Math.round(r.trimp_edwards)) : '—',
        rarity: card.rarity,
        mood: moodFromActivity(r),
        badges: (card.badges ?? []).slice(0, 3),
        stats: buildCardStats(r),
        zonePct: zonePctFromDetail(r),
        polyline: r.summary_polyline ?? null,
        paceShape: paceShapeFromDetail(r),
        startDate: r.start_date_local,
    };
}

export function pickFeaturedKartu(runs: ReadonlyArray<ActivityDetail>): FeaturedCard | null {
    let best: FeaturedCard | null = null;
    let bestRank = -1;
    let bestDate = '';
    for (const r of runs) {
        const card = r.activity?.run_card;
        if (!card) continue;
        const rank = RARITY_RANK[card.rarity];
        const date = r.start_date_local ?? '';
        if (rank > bestRank || (rank === bestRank && date > bestDate)) {
            best = toFeaturedCard(r, card);
            bestRank = rank;
            bestDate = date;
        }
    }
    return best;
}

export function kartuStripItem(run: ActivityDetail): StripItem | null {
    const card: RunCard | undefined = run.activity?.run_card;
    if (!card) return null;
    return {
        key: `card-${card.id}`,
        cardId: card.id,
        name: card.special_move,
        rarity: card.rarity,
        date: formatRelativeId(run.start_date_local),
        polyline: run.summary_polyline ?? null,
    };
}

export function formatSignedForm(form: number): string {
    return form >= 0 ? `+${form.toFixed(1)}` : form.toFixed(1);
}

export function vibeSubtitleFor(label: string): string {
    return `kamu lagi ${label.toLowerCase()}.`;
}

export const MOOD_UPPER: Record<Mood, string> = {
    nyala: 'NYALA',
    enteng: 'ENTENG',
    oleng: 'OLENG',
    lemes: 'LEMES',
    mumet: 'MUMET',
    adem: 'ADEM',
};

export function formatIdDateUpper(iso: string | null): string {
    if (iso == null) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return formatShortWeekdayDateId(date).toUpperCase();
}

export function shortenLocation(name: string | null): string | null {
    if (name === null || name === '') return null;
    const parts = name.split(',').map((s) => s.trim()).filter(Boolean);
    if (parts.length === 0) return null;
    return parts.length === 1 ? parts[0] : `${parts[0]}, ${parts[1]}`;
}

export function formatWeather(tempC: number | null, humidityPct: number | null, rain: boolean | null): string | null {
    const bits: string[] = [];
    if (tempC !== null) bits.push(`${Math.round(tempC)}°C`);
    if (humidityPct !== null) bits.push(`${Math.round(humidityPct)}%`);
    if (rain === true) bits.push('hujan');
    return bits.length > 0 ? bits.join(' · ') : null;
}

// Indonesian descriptors for Kondisi card subtitles. Thresholds are rough
// runner-folklore numbers, not medical advice.
export function ctlHint(ctl: number | null | undefined): string {
    if (ctl == null) return '';
    if (ctl < 25) return 'lagi dibangun';
    if (ctl < 50) return 'naik tipis';
    if (ctl < 80) return 'stabil';
    return 'tinggi';
}

export function atlHint(atl: number | null | undefined): string {
    if (atl == null) return '';
    if (atl < 25) return 'fresh';
    if (atl < 55) return 'wajar';
    if (atl < 85) return 'lelah';
    return 'berat';
}

export function strainHint(strain: number | null | undefined): string {
    if (strain == null) return '';
    if (strain < 250) return 'ringan';
    if (strain < 500) return 'sedang';
    return 'berat';
}

export function monotonyHint(monotony: number | null | undefined): string {
    if (monotony == null) return '';
    if (monotony < 1.5) return 'sehat';
    if (monotony < 2) return 'tinggi';
    return 'monoton';
}
