import { describe, expect, it } from 'vitest';

import type { ActivityDetail } from '@/types/inertia';

import { moodFromActivity } from './moodFromActivity';

function detail(overrides: Partial<ActivityDetail> = {}): ActivityDetail {
    return {
        id: 1,
        activity_id: 1,
        name: null,
        start_date_local: null,
        distance: null,
        elapsed_time: null,
        average_heartrate: null,
        trimp_edwards: null,
        ...overrides,
    };
}

describe('moodFromActivity', () => {
    it('returns dim for runs with no/low TRIMP', () => {
        expect(moodFromActivity(detail({ trimp_edwards: null }))).toBe('chill');
        expect(moodFromActivity(detail({ trimp_edwards: 20 }))).toBe('chill');
    });

    it('returns spinning for short interval-ish runs', () => {
        expect(moodFromActivity(detail({ trimp_edwards: 40 }))).toBe(
            'overloaded',
        );
    });

    it('returns glow for solid aerobic runs', () => {
        expect(moodFromActivity(detail({ trimp_edwards: 75 }))).toBe('blazing');
    });

    it('returns squished for long-distance drained runs', () => {
        expect(
            moodFromActivity(detail({ trimp_edwards: 100, distance: 15000 })),
        ).toBe('wobbly');
    });

    it('returns bouncy for solid hard sessions', () => {
        expect(moodFromActivity(detail({ trimp_edwards: 150 }))).toBe('easy');
    });

    it('returns wobble for crushing efforts', () => {
        expect(moodFromActivity(detail({ trimp_edwards: 220 }))).toBe('gassed');
    });

    it('reads a crushing effort as a quality win when the run is a tagged race/workout', () => {
        expect(
            moodFromActivity(detail({ trimp_edwards: 220, workout_type: 3 })),
        ).toBe('blazing');
        expect(
            moodFromActivity(detail({ trimp_edwards: 220, workout_type: 1 })),
        ).toBe('blazing');
    });
});
