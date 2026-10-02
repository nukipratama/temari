import type { Mood } from '@/types/inertia';

export const EFFORT_MIN = 1;
export const EFFORT_MAX = 10;

const EFFORT_WORDS: Readonly<Record<number, string>> = {
    1: 'very easy',
    2: 'easy',
    3: 'moderate',
    4: 'somewhat hard',
    5: 'hard',
    6: 'harder',
    7: 'very hard',
    8: 'really hard',
    9: 'nearly everything',
    10: 'maximal',
};

/** Foster's modified Borg CR-10 descriptor for a session effort score. */
export function effortWord(score: number): string {
    return EFFORT_WORDS[score] ?? '';
}

export type EffortBand = 'easy' | 'steady' | 'hard';

/** Seiler's session-RPE three-zone split: 1–4 easy, 5–6 steady, 7–10 hard. The stored score stays 1–10. */
export function effortBand(score: number): EffortBand {
    if (score <= 4) {
        return 'easy';
    }

    return score <= 6 ? 'steady' : 'hard';
}

export type EffortPose = Mood | 'neutral';

/** Temari's face for a draft score: neutral until rated, then two scores per pose. */
export function effortPose(score: number | null): EffortPose {
    if (score === null) {
        return 'neutral';
    }
    if (score <= 2) {
        return 'chill';
    }
    if (score <= 4) {
        return 'easy';
    }
    if (score <= 6) {
        return 'blazing';
    }

    return score <= 8 ? 'gassed' : 'overloaded';
}
