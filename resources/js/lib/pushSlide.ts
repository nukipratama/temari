import { router } from '@inertiajs/react';

import type { NavDirection } from '@/lib/nav';

import { navDirection, screenForPath } from '@/lib/nav';

interface HistoryPage {
    page?: unknown;
}

let active: ViewTransition | null = null;

/** A finger-driven screen, where pushed screens slide; read at event time, like reduced motion. */
export function slidesScreens(): boolean {
    return window.matchMedia?.('(pointer: coarse)').matches ?? false;
}

export function prefersReducedMotion(): boolean {
    return (
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
    );
}

/**
 * Runs `update` inside a view transition that slides the new screen over the
 * old one, or the old one off the new, per `data-nav-slide` in `app.css`.
 */
export function startSlide(
    direction: NavDirection,
    update: () => void | Promise<void>,
): ViewTransition | null {
    if (
        typeof document.startViewTransition !== 'function' ||
        document.visibilityState === 'hidden'
    ) {
        void update();
        return null;
    }

    const root = document.documentElement;
    root.dataset.navSlide = direction;
    const transition = document.startViewTransition(update);
    active = transition;
    const clear = () => {
        if (active === transition) {
            active = null;
            delete root.dataset.navSlide;
        }
    };
    transition.ready.catch(() => undefined);
    transition.finished.then(clear, clear);

    return transition;
}

function untilPageSwapped(): Promise<void> {
    return new Promise((resolve) => {
        const done = () => {
            offNavigate();
            offStart();
            resolve();
        };
        const offNavigate = router.on('navigate', done);
        const offStart = router.on('start', done);
    });
}

/**
 * Slides browser and Android back (and forward) between a pushed screen and
 * its parent, read from the URL since the history state is encrypted. Inertia
 * restores those pages from history on `popstate`, so the event is held back
 * from it until the transition has captured the old screen, then replayed.
 * Returns the unsubscribe.
 */
export function slideHistoryTraversals(
    currentComponent: () => string,
): () => void {
    let replaying = false;

    const onPopstate = (event: PopStateEvent) => {
        if (replaying || event.hasUAVisualTransition) {
            return;
        }

        const direction =
            (event.state as HistoryPage | null)?.page === undefined
                ? null
                : navDirection(
                      currentComponent(),
                      screenForPath(window.location.pathname),
                  );
        if (direction === null || !slidesScreens() || prefersReducedMotion()) {
            return;
        }

        event.stopImmediatePropagation();
        startSlide(direction, () => {
            const swapped = untilPageSwapped();
            replaying = true;
            window.dispatchEvent(
                new PopStateEvent('popstate', { state: event.state }),
            );
            replaying = false;

            return swapped;
        });
    };

    window.addEventListener('popstate', onPopstate, { capture: true });

    return () => {
        window.removeEventListener('popstate', onPopstate, { capture: true });
    };
}
