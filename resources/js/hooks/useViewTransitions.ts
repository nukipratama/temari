import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Opts real navigations into Inertia's own View Transitions.
 *
 * Inertia's `Page.swap()` wraps the swap in `document.startViewTransition()`
 * when a visit carries `viewTransition`; that option defaults to false, so this
 * only decides which visits deserve one.
 *
 * `showProgress` separates a real navigation from the background `only`/`except`
 * reloads this app runs for AI polling and card reveals, which must not animate.
 * A link's own `viewTransition` callback (the run-card morph) is kept on a real
 * navigation and dropped under reduced motion.
 */
export default function useViewTransitions(): void {
    useEffect(
        () =>
            router.on('before', (event) => {
                const { visit } = event.detail;

                // Read at event time, not on mount: someone who turns the OS
                // setting on mid-session gets no animation on their next tap.
                const reducedMotion = window.matchMedia?.(
                    '(prefers-reduced-motion: reduce)',
                ).matches;

                if (!visit.showProgress) {
                    return;
                }

                visit.viewTransition = reducedMotion
                    ? false
                    : visit.viewTransition || true;
            }),
        [],
    );
}
