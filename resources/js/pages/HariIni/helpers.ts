import type { TemariPose } from '@/components/temari/TemariProto';
import { moodFromActivity } from '@/lib/moodFromActivity';
import { formatDuration, formatKm, formatRelativeId } from '@/lib/pace';
import { RARITY_LABELS, prettyBadge } from '@/lib/runcard';
import type { ActivityDetail, Rarity, RunCard } from '@/types/inertia';

export interface FeaturedCard {
    activityId: number;
    name: string;
    subtitle: string;
    km: string;
    durasi: string;
    trimp: string;
    rarity: Rarity;
    tags: ReadonlyArray<string>;
    startDate: string | null;
}

export interface StripItem {
    key: string;
    activityId: number;
    name: string;
    rarity: Rarity;
    date: string;
}

export const VIBE_TO_POSE: Record<string, TemariPose> = {
    pumped: 'pumped',
    bouncy: 'excited',
    fresh: 'proud',
    steady: 'observational',
    cooked: 'wobble',
    worn_down: 'wobble',
    stretched_thin: 'wobble',
    hibernating: 'reading',
};

const MOOD_TO_POSE: Record<string, TemariPose> = {
    nyala: 'proud',
    enteng: 'excited',
    lemes: 'wobble',
    oleng: 'wobble',
    mumet: 'wobble',
    adem: 'reading',
};

const RARITY_RANK: Record<Rarity, number> = {
    common: 0,
    uncommon: 1,
    rare: 2,
    epic: 3,
    legendary: 4,
};

export function pickFeaturedKartu(runs: ReadonlyArray<ActivityDetail>): FeaturedCard | null {
    let best: FeaturedCard | null = null;
    let bestRank = -1;
    let bestDate = '';
    for (const r of runs) {
        const card = r.activity?.runCard;
        if (!card) continue;
        const rank = RARITY_RANK[card.rarity];
        const date = r.start_date_local ?? '';
        if (rank > bestRank || (rank === bestRank && date > bestDate)) {
            best = {
                activityId: r.activity_id,
                name: card.special_move,
                subtitle: `${RARITY_LABELS[card.rarity]} · ${formatRelativeId(r.start_date_local)}`,
                km: formatKm(r.distance),
                durasi: r.moving_time != null ? formatDuration(r.moving_time) : '—',
                trimp: r.trimp_edwards != null ? String(Math.round(r.trimp_edwards)) : '—',
                rarity: card.rarity,
                tags: (card.badges ?? []).slice(0, 2).map(prettyBadge),
                startDate: r.start_date_local,
            };
            bestRank = rank;
            bestDate = date;
        }
    }
    return best;
}

export function kartuStripItem(run: ActivityDetail): StripItem | null {
    const card: RunCard | undefined = run.activity?.runCard;
    if (!card) return null;
    return {
        key: `card-${card.id}`,
        activityId: run.activity_id,
        name: card.special_move,
        rarity: card.rarity,
        date: formatRelativeId(run.start_date_local),
    };
}

export function formatSignedForm(form: number): string {
    return form >= 0 ? `+${form.toFixed(1)}` : form.toFixed(1);
}

export function vibeSubtitleFor(label: string): string {
    return `kamu lagi ${label.toLowerCase()}.`;
}

export function poseForRun(run: ActivityDetail): TemariPose {
    const mood = moodFromActivity(run);
    return MOOD_TO_POSE[mood] ?? 'observational';
}
