import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Opts real navigations into Inertia's own View Transitions.
 *
 * The transition itself is the framework's: `Page.swap()` wraps the component
 * swap in `document.startViewTransition()` when a visit carries
 * `viewTransition`, and already declines on a browser without the API and on a
 * hidden tab. That option is per-visit and defaults to false, so all this does
 * is decide which visits deserve one.
 *
 * Two reasons this succeeds where #396's page transition failed. It snapshots
 * the DOM that is already on screen rather than keying `<main>`, so nothing
 * remounts and there is no frame where the old page has gone and the new one
 * has not arrived — which is what made that attempt read as "old page → blank →
 * fade in". And the swap happens inside the transition, so the browser
 * cross-fades between two real frames instead of animating up from opacity 0.
 *
 * `showProgress` is Inertia's own flag for separating a real navigation from
 * the background `only`/`except` reloads this app runs for AI polling and card
 * reveals. Those must not animate — a poll tick is not a navigation.
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

                if (visit.showProgress && !reducedMotion) {
                    visit.viewTransition = true;
                }
            }),
        [],
    );
}
