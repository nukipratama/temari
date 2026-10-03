import type { CSSProperties } from 'react';

const STAGGER_LEAD_SECONDS = 0.05;
const STAGGER_STEP_SECONDS = 0.06;

/** Feeds `.reveal`'s `--reveal-delay` so a group of siblings lands in order
 *  rather than all at once. `index` is the child's position in the group. */
export function revealDelay(index: number): CSSProperties {
    const seconds = STAGGER_LEAD_SECONDS + index * STAGGER_STEP_SECONDS;
    return { '--reveal-delay': `${seconds}s` } as CSSProperties;
}
