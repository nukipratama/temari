import type { TemariPose } from '@/components/temari/TemariProto';
import { moodFromActivity } from '@/lib/moodFromActivity';
import type { ActivityDetail, FormStatus, Mood } from '@/types/inertia';

export const MOOD_TO_POSE: Record<Mood, TemariPose> = {
    nyala: 'proud',
    enteng: 'excited',
    lemes: 'wobble',
    oleng: 'wobble',
    mumet: 'wobble',
    adem: 'reading',
};

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

export function poseForRun(run: ActivityDetail): TemariPose {
    const mood = moodFromActivity(run);
    return MOOD_TO_POSE[mood] ?? 'observational';
}

export function poseForFormStatus(status: FormStatus | null): TemariPose {
    switch (status) {
        case 'fresh':
            return 'proud';
        case 'optimal':
            return 'observational';
        case 'fatigued':
            return 'wobble';
        case 'overreaching':
            return 'reading';
        default:
            return 'observational';
    }
}
