import { describe, expect, it } from 'vitest';

import type { ActivityDetail } from '@/types/inertia';

import { MOOD_TO_POSE, poseForFormStatus, poseForRun } from './temariPose';

function runWith(overrides: Partial<ActivityDetail>): ActivityDetail {
    return {
        id: 1,
        activity_id: 99,
        name: 'Lari',
        start_date_local: '2026-05-20T07:00',
        distance: 5000,
        elapsed_time: 1800,
        trimp_edwards: 60,
        average_heartrate: 145,
        ...overrides,
    } as ActivityDetail;
}

describe('MOOD_TO_POSE', () => {
    it('maps every mood to a pose', () => {
        expect(MOOD_TO_POSE.blazing).toBe('proud');
        expect(MOOD_TO_POSE.easy).toBe('excited');
        expect(MOOD_TO_POSE.chill).toBe('reading');
    });
});

describe('poseForRun', () => {
    it('maps moodFromActivity output to a Temari pose', () => {
        const run = runWith({
            trimp_edwards: 200,
            distance: 12_000,
            elapsed_time: 3_600,
            average_heartrate: 170,
        });
        expect(poseForRun(run)).toBe('wobble');
    });

    it('short-circuits past moodFromActivity when a mood override is given', () => {
        const run = runWith({
            trimp_edwards: 200,
            distance: 12_000,
            elapsed_time: 3_600,
            average_heartrate: 170,
        });
        expect(poseForRun(run, 'blazing')).toBe('proud');
    });
});

describe('poseForFormStatus', () => {
    it('maps each form status to its pose', () => {
        expect(poseForFormStatus('fresh')).toBe('proud');
        expect(poseForFormStatus('optimal')).toBe('observational');
        expect(poseForFormStatus('fatigued')).toBe('wobble');
        expect(poseForFormStatus('overreaching')).toBe('reading');
    });

    it('defaults to observational for null', () => {
        expect(poseForFormStatus(null)).toBe('observational');
    });
});
