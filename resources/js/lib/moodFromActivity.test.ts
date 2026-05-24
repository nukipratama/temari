import { describe, expect, it } from 'vitest';
import { moodFromActivity } from './moodFromActivity';
import type { ActivityDetail } from '@/types/inertia';

function detail(overrides: Partial<ActivityDetail> = {}): ActivityDetail {
    return {
        id: 1,
        activity_id: 1,
        name: null,
        start_date_local: null,
        distance: null,
        moving_time: null,
        average_heartrate: null,
        trimp_edwards: null,
        ...overrides,
    };
}

describe('moodFromActivity', () => {
    it('returns dim for runs with no/low TRIMP', () => {
        expect(moodFromActivity(detail({ trimp_edwards: null }))).toBe('adem');
        expect(moodFromActivity(detail({ trimp_edwards: 20 }))).toBe('adem');
    });

    it('returns spinning for short interval-ish runs', () => {
        expect(moodFromActivity(detail({ trimp_edwards: 40 }))).toBe('mumet');
    });

    it('returns glow for solid aerobic runs', () => {
        expect(moodFromActivity(detail({ trimp_edwards: 75 }))).toBe('nyala');
    });

    it('returns squished for long-distance drained runs', () => {
        expect(moodFromActivity(detail({ trimp_edwards: 100, distance: 15000 }))).toBe('oleng');
    });

    it('returns bouncy for solid hard sessions', () => {
        expect(moodFromActivity(detail({ trimp_edwards: 150 }))).toBe('enteng');
    });

    it('returns wobble for crushing efforts', () => {
        expect(moodFromActivity(detail({ trimp_edwards: 220 }))).toBe('lemes');
    });
});
