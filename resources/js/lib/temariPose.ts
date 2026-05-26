import type { TemariPose } from '@/components/temari/TemariProto';
import type { Mood } from '@/types/inertia';

export const MOOD_TO_POSE: Record<Mood, TemariPose> = {
    nyala: 'proud',
    enteng: 'excited',
    lemes: 'wobble',
    oleng: 'wobble',
    mumet: 'wobble',
    adem: 'reading',
};
