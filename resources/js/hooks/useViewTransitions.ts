import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

import {
    prefersReducedMotion,
    slideHistoryTraversals,
    visitDirection,
} from '@/lib/pushSlide';

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
 *
 * On a phone, a push or pop already slid on the tap (lib/pendingTab), so its
 * swap replaces the skeleton without a second transition; history traversals
 * between the same screens slide through `slideHistoryTraversals`.
 */
export default function useViewTransitions(component: string): void {
    const componentRef = useRef(component);

    useEffect(() => {
        componentRef.current = component;
    }, [component]);

    useEffect(() => slideHistoryTraversals(() => componentRef.current), []);

    useEffect(
        () =>
            router.on('before', (event) => {
                const { visit } = event.detail;

                if (!visit.showProgress) {
                    return;
                }

                const slid =
                    visitDirection(componentRef.current, visit.url) !== null;

                // Read at event time, not on mount: someone who turns the OS
                // setting on mid-session gets no animation on their next tap.
                visit.viewTransition =
                    slid || prefersReducedMotion()
                        ? false
                        : visit.viewTransition || true;
            }),
        [],
    );
}
