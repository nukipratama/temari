import { describe, expect, it } from 'vitest';
import { MOOD_TO_POSE, VIBE_TO_POSE, poseForFormStatus, poseForRun } from './temariPose';
import type { ActivityDetail } from '@/types/inertia';

function runWith(overrides: Partial<ActivityDetail>): ActivityDetail {
    return {
        id: 1,
        activity_id: 99,
        name: 'Lari',
        start_date_local: '2026-05-20T07:00',
        distance: 5000,
        moving_time: 1800,
        trimp_edwards: 60,
        average_heartrate: 145,
        ...overrides,
    } as ActivityDetail;
}

describe('MOOD_TO_POSE', () => {
    it('maps every mood to a pose', () => {
        expect(MOOD_TO_POSE.nyala).toBe('proud');
        expect(MOOD_TO_POSE.enteng).toBe('excited');
        expect(MOOD_TO_POSE.adem).toBe('reading');
    });
});

describe('VIBE_TO_POSE', () => {
    it('maps known vibe states to poses', () => {
        expect(VIBE_TO_POSE.pumped).toBe('pumped');
        expect(VIBE_TO_POSE.bouncy).toBe('excited');
        expect(VIBE_TO_POSE.fresh).toBe('proud');
        expect(VIBE_TO_POSE.hibernating).toBe('reading');
    });

    it('has no entry for an unknown vibe (caller falls back)', () => {
        expect(VIBE_TO_POSE.mysterious).toBeUndefined();
    });
});

describe('poseForRun', () => {
    it('maps moodFromActivity output to a Temari pose', () => {
        const run = runWith({ trimp_edwards: 200, distance: 12_000, moving_time: 3_600, average_heartrate: 170 });
        expect(poseForRun(run)).toMatch(/proud|excited|wobble|reading|observational/);
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
