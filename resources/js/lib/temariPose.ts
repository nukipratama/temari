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

// Prefer the persisted backend mood when the caller has it; only fall back to
// the frontend heuristic for runs with no post-run StoryLine yet.
export function poseForRun(run: ActivityDetail, mood?: Mood | null): TemariPose {
    const resolved = mood ?? moodFromActivity(run);
    return MOOD_TO_POSE[resolved] ?? 'observational';
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
