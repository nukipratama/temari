import type { Effort } from '@/types/inertia';

export const EFFORT_LABEL: Record<Effort, string> = {
    easy: 'easy',
    steady: 'steady',
    hard: 'hard',
    rest: 'rest',
    unknown: 'unknown',
};

/**
 * The 3px leading-edge stripe MASTER.md gives every run row, keyed by
 * effort (design-system/temari/MASTER.md § Effort colors). Square corners,
 * border only — never a fill — so it composes under any row background.
 * `rest` and `unknown` are both achromatic on purpose; the border style
 * (dashed vs solid) is what tells them apart, not a color.
 */
export const EFFORT_STRIPE_CLASS: Record<Effort, string> = {
    easy: 'border-l-[3px] border-solid border-leaf',
    steady: 'border-l-[3px] border-solid border-citrus',
    hard: 'border-l-[3px] border-solid border-ember',
    rest: 'border-l-[3px] border-dashed border-border',
    unknown: 'border-l-[3px] border-solid border-border',
};
