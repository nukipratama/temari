import type { Effort } from '@/types/inertia';

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

/**
 * The same effort colors as {@link EFFORT_STRIPE_CLASS}, moved to the bottom
 * edge for the calendar day cell, which already spends its leading edge on
 * the today/current-month treatment.
 */
export const EFFORT_EDGE_CLASS: Record<Effort, string> = {
    easy: 'border-b-[3px] border-solid border-leaf',
    steady: 'border-b-[3px] border-solid border-citrus',
    hard: 'border-b-[3px] border-solid border-ember',
    rest: 'border-b-[3px] border-dashed border-border',
    unknown: 'border-b-[3px] border-solid border-border',
};

/**
 * A plan day's effort, read off its `session_type` (design-system/temari/MASTER.md
 * § Effort colors: easy → leaf, long/tempo → citrus, interval/race → ember,
 * rest stays achromatic). The one place session type maps to an effort — the
 * week strip and plan-widget day cells feed this into
 * {@link EFFORT_STRIPE_CLASS}/{@link EFFORT_EDGE_CLASS} rather than keeping a
 * second color table of their own.
 */
export function sessionTypeEffort(sessionType: string): Effort {
    switch (sessionType) {
        case 'easy':
            return 'easy';
        case 'long':
        case 'tempo':
            return 'steady';
        case 'interval':
        case 'race':
            return 'hard';
        case 'rest':
            return 'rest';
        default:
            return 'unknown';
    }
}
