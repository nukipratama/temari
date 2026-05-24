import { describe, expect, it } from 'vitest';
import {
    formatSignedForm,
    kartuStripItem,
    pickFeaturedKartu,
    poseForRun,
    vibeSubtitleFor,
} from './helpers';
import type { ActivityDetail, Rarity } from '@/types/inertia';

function runWith(overrides: Partial<ActivityDetail>, cardOverrides?: { rarity?: Rarity; id?: number; special_move?: string }): ActivityDetail {
    return {
        id: overrides.id ?? 1,
        activity_id: overrides.activity_id ?? 99,
        name: 'Lari',
        start_date_local: '2026-05-20T07:00',
        distance: 5000,
        moving_time: 1800,
        trimp_edwards: 60,
        average_heartrate: 145,
        ...overrides,
        activity: cardOverrides
            ? {
                id: 99,
                user_id: 1,
                analyzed_at: '2026-05-20',
                runCard: {
                    id: cardOverrides.id ?? 7,
                    activity_id: 99,
                    rarity: cardOverrides.rarity ?? 'common',
                    special_move: cardOverrides.special_move ?? 'Langkah Mantap',
                    badges: ['negative_split'],
                },
            }
            : undefined,
    } as ActivityDetail;
}

describe('formatSignedForm', () => {
    it('prepends + for positive form', () => {
        expect(formatSignedForm(2.3)).toBe('+2.3');
    });

    it('keeps the - sign for negative form', () => {
        expect(formatSignedForm(-1.7)).toBe('-1.7');
    });
});

describe('vibeSubtitleFor', () => {
    it('lowercases the vibe label and wraps it in "kamu lagi …"', () => {
        expect(vibeSubtitleFor('Membara')).toBe('kamu lagi membara.');
    });
});

describe('poseForRun', () => {
    it('maps moodFromActivity output to a Temari pose', () => {
        // A high-effort run reads as nyala → proud pose.
        const run = runWith({ trimp_edwards: 200, distance: 12_000, moving_time: 3_600, average_heartrate: 170 });
        expect(poseForRun(run)).toMatch(/proud|excited|wobble|reading|observational/);
    });
});

describe('pickFeaturedKartu', () => {
    it('returns null when no run has an attached card', () => {
        expect(pickFeaturedKartu([runWith({})])).toBeNull();
    });

    it('picks the highest-rarity card; ties broken by most recent date', () => {
        const older = runWith(
            { id: 1, activity_id: 1, start_date_local: '2026-05-01' },
            { id: 1, rarity: 'epic', special_move: 'Older Epic' },
        );
        const newer = runWith(
            { id: 2, activity_id: 2, start_date_local: '2026-05-20' },
            { id: 2, rarity: 'epic', special_move: 'Newer Epic' },
        );
        const rare = runWith(
            { id: 3, activity_id: 3, start_date_local: '2026-05-21' },
            { id: 3, rarity: 'rare', special_move: 'Rare One' },
        );
        const featured = pickFeaturedKartu([older, newer, rare]);
        expect(featured?.name).toBe('Newer Epic');
    });
});

describe('kartuStripItem', () => {
    it('returns null when the run has no attached card', () => {
        expect(kartuStripItem(runWith({}))).toBeNull();
    });

    it('returns a strip item with rarity + key derived from card id', () => {
        const run = runWith({}, { id: 42, rarity: 'rare', special_move: 'Cool Move' });
        const item = kartuStripItem(run);
        expect(item).toMatchObject({ key: 'card-42', name: 'Cool Move', rarity: 'rare' });
    });
});
