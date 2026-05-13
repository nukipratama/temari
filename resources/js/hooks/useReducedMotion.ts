import { useReducedMotion as useFmReducedMotion } from 'framer-motion';

/**
 * Thin re-export of Framer Motion's reduced-motion detection so the rest
 * of the app imports from a stable internal path. Returns `true` when the
 * user has `prefers-reduced-motion: reduce` set (system or browser).
 *
 * Animations should short-circuit (skip transform/opacity changes, render
 * the final state immediately) when this returns `true`.
 */
export function useReducedMotion(): boolean {
    return useFmReducedMotion() ?? false;
}
