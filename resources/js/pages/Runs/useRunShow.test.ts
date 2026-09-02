import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { ActivityDetail, StoryLine } from '@/types/inertia';

import { useRunShow, type RunCardDetail } from './useRunShow';

const detail: ActivityDetail = {
    id: 11,
    activity_id: 99,
    name: 'Morning Run',
    start_date_local: '2026-05-10T07:00:00',
    distance: 10000,
    total_elevation_gain: 120,
    elapsed_time: 3600,
    average_heartrate: 150,
    trimp_edwards: 70,
    stream_summary: {
        per_km: [
            { km: 1, pace: '6:00', avg_hr: 150, avg_cadence_spm: 170 },
            { km: 2, pace: '5:45', avg_hr: 155, avg_cadence_spm: 173 },
        ],
    },
    max_heartrate: 175,
    average_cadence: 85,
    weather_temp_c: 32,
    location_name: 'Senayan, Jakarta Pusat',
};

const runCard: RunCardDetail = {
    id: 1,
    activity_id: 99,
    rarity: 'epic',
    special_move: 'Iron Lungs',
    badges: ['negative_split'],
    edition: { index: 3, total: 5 },
    flavor_analysis: {
        id: 2,
        status: 'done',
        content: 'Breathing strong to the end.',
        type: 'card_flavor',
        subject_type: String.raw`App\Models\RunCard`,
        subject_id: 1,
        discriminator: null,
    },
    public_share_url: '/activities/255',
};

const storyLine: StoryLine = {
    id: 1,
    user_id: 1,
    activity_id: 99,
    kind: 'post_run',
    mood: 'blazing',
    speech: null,
    sigil_pattern: 'ssss',
    for_date: null,
};

function hookProps(overrides: Partial<Parameters<typeof useRunShow>[0]> = {}) {
    return {
        detail,
        card: runCard,
        storyLine,
        moodFallback: 'chill' as const,
        ...overrides,
    };
}

describe('useRunShow', () => {
    it('uses the story-line mood over the fallback when present', () => {
        const { result } = renderHook(() => useRunShow(hookProps()));
        expect(result.current.mood).toBe('blazing');
    });

    it('falls back to moodFallback when there is no story line', () => {
        const { result } = renderHook(() =>
            useRunShow(hookProps({ storyLine: null, moodFallback: 'wobbly' })),
        );
        expect(result.current.mood).toBe('wobbly');
    });

    it('formats km, pace, hr, and trimp from the detail', () => {
        const { result } = renderHook(() => useRunShow(hookProps()));
        expect(result.current.km).toBe('10.00');
        expect(result.current.pace).not.toBe('—');
        expect(result.current.hr).toBe(150);
        expect(result.current.trimp).toBe(70);
    });

    it('derives split data from the stream summary', () => {
        const { result } = renderHook(() => useRunShow(hookProps()));
        expect(result.current.perKm).toHaveLength(2);
        expect(result.current.partialSplit).toBeNull();
        expect(result.current.laps).toEqual([]);
    });

    it('derives the watch laps from the stream summary', () => {
        const { result } = renderHook(() =>
            useRunShow(
                hookProps({
                    detail: {
                        ...detail,
                        stream_summary: {
                            ...detail.stream_summary,
                            laps: [
                                {
                                    lap: 1,
                                    distance_m: 647,
                                    elapsed_sec: 233,
                                    pace: '6:00',
                                },
                            ],
                        },
                    },
                }),
            ),
        );
        expect(result.current.laps).toHaveLength(1);
        expect(result.current.laps[0].distance_m).toBe(647);
    });

    it('falls back to an empty stream summary when the run has none', () => {
        const { result } = renderHook(() =>
            useRunShow(
                hookProps({ detail: { ...detail, stream_summary: null } }),
            ),
        );
        expect(result.current.perKm).toEqual([]);
        expect(result.current.partialSplit).toBeNull();
    });

    it('caps the share-card badge tags at 3', () => {
        const { result } = renderHook(() =>
            useRunShow(
                hookProps({
                    card: {
                        ...runCard,
                        badges: [
                            'negative_split',
                            'negative_split',
                            'negative_split',
                            'negative_split',
                        ],
                    },
                }),
            ),
        );
        expect(result.current.cardBadges).toHaveLength(3);
    });

    it('builds share data from the card, or null when there is no card', () => {
        const { result: withCard } = renderHook(() => useRunShow(hookProps()));
        expect(withCard.current.shareData).toMatchObject({
            id: 1,
            name: 'Iron Lungs',
            shareUrl: '/activities/255',
            mood: 'blazing',
        });

        const { result: withoutCard } = renderHook(() =>
            useRunShow(hookProps({ card: null })),
        );
        expect(withoutCard.current.shareData).toBeNull();
    });
});
