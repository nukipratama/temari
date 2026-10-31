import { router } from '@inertiajs/react';

import type { ContextualOrigin, NavDirection } from '@/lib/nav';

import { BACK_TARGETS, backTargetFor } from '@/lib/nav';
import { readContextualOrigin } from '@/lib/navigationMemory';
import { RACE_PATH } from '@/lib/navRoutes';

interface HistoryPage {
    page?: unknown;
}

let active: ViewTransition | null = null;

export function isPushedScreen(component: string): boolean {
    return Object.hasOwn(BACK_TARGETS, component);
}

const SCREEN_PATHS: Readonly<Record<string, string>> = {
    '/': 'Home',
    '/plan': 'Plan',
    [RACE_PATH]: 'Race',
    '/trends': 'Trends',
    '/history': 'History',
    '/inbox': 'Inbox',
    '/profile': 'Profile',
    '/settings': 'Settings/Index',
};

/** The page component a pathname renders, for the tab and pushed screens only. */
export function screenForPath(pathname: string): string | null {
    if (/^\/activities\/\d+$/.test(pathname)) {
        return 'Runs/Show';
    }

    return SCREEN_PATHS[pathname] ?? null;
}

/** A pop returns to `from`'s back target; a push opens any pushed screen; anything else is neither. */
export function navDirection(
    from: string,
    to: string | null,
    origin: ContextualOrigin | null = null,
): NavDirection | null {
    if (to === null) {
        return null;
    }

    const back = backTargetFor(from, origin);
    if (
        back !== null &&
        screenForPath(back.href.split(/[?#]/)[0] ?? '') === to
    ) {
        return 'pop';
    }

    return isPushedScreen(to) ? 'push' : null;
}

/** A finger-driven screen, where pushed screens slide; read at event time, like reduced motion. */
export function slidesScreens(): boolean {
    return window.matchMedia?.('(pointer: coarse)').matches ?? false;
}

export function prefersReducedMotion(): boolean {
    return (
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
    );
}

/** The slide a phone plays for a visit from `from` to `url`, or null for the cross-fade. */
export function visitDirection(from: string, url: URL): NavDirection | null {
    if (!slidesScreens() || url.pathname === window.location.pathname) {
        return null;
    }

    return navDirection(
        from,
        screenForPath(url.pathname),
        readContextualOrigin(),
    );
}

function historyIndex(): number | null {
    return window.navigation?.currentEntry?.index ?? null;
}

/**
 * Back from a pushed screen pops and forward into one pushes. Without the
 * Navigation API to tell back from forward, only a return to the back target
 * slides.
 */
function traversalDirection(
    from: string,
    to: string | null,
    back: boolean | null,
): NavDirection | null {
    if (back === null) {
        return navDirection(from, to, readContextualOrigin()) === 'pop'
            ? 'pop'
            : null;
    }

    if (back) {
        return isPushedScreen(from) ? 'pop' : null;
    }

    return to !== null && isPushedScreen(to) ? 'push' : null;
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
        const offStart = router.on('start', (event) => {
            if (!event.detail.visit.async) {
                done();
            }
        });
    });
}

/**
 * Slides browser and Android back and forward to and from pushed screens,
 * read from the URL since the history state is encrypted. Inertia restores
 * those pages from history on `popstate`, so the event is held back from it
 * until the transition has captured the old screen, then replayed. Returns the
 * unsubscribe.
 */
export function slideHistoryTraversals(
    currentComponent: () => string,
): () => void {
    let replaying = false;
    let shownPath = window.location.pathname;
    let shownIndex = historyIndex();

    const offNavigate = router.on('navigate', () => {
        shownPath = window.location.pathname;
        shownIndex = historyIndex();
    });

    const onPopstate = (event: PopStateEvent) => {
        const path = window.location.pathname;
        if (
            replaying ||
            event.hasUAVisualTransition ||
            (event.state as HistoryPage | null)?.page === undefined ||
            path === shownPath
        ) {
            return;
        }

        const index = historyIndex();
        const direction = traversalDirection(
            currentComponent(),
            screenForPath(path),
            index === null || shownIndex === null || index === shownIndex
                ? null
                : index < shownIndex,
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
        offNavigate();
        window.removeEventListener('popstate', onPopstate, { capture: true });
    };
}
