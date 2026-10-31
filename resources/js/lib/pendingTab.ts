import type { PendingVisit } from '@inertiajs/core';

import { router } from '@inertiajs/react';

import type { NavDirection } from '@/lib/nav';
import type { TabId } from '@/lib/navRoutes';

import { ITEMS, navDirection, screenForPath } from '@/lib/nav';
import { navTabFor, RACE_PATH, RACE_TAB } from '@/lib/navRoutes';
import {
    prefersReducedMotion,
    slidesScreens,
    startSlide,
} from '@/lib/pushSlide';

/** Where the visit lands: a tab, or (on a phone, by push or pop) a pushed screen. */
type Destination = { tab: TabId; screen: null } | { tab: null; screen: string };

export type PendingTab = Destination & {
    /** The page component the visit started from. */
    from: string;
    href: string;
    /** The scroll position on the page the visit started from. */
    scrollY: number;
    visit: PendingVisit;
};

type Target = Destination & { from: string };
type Tap = { tab: TabId; from: string };

let armed: Tap | null = null;
let pending: PendingTab | null = null;
let sliding: { visit: PendingVisit; transition: ViewTransition | null } | null =
    null;
const listeners = new Set<() => void>();

function setPending(next: PendingTab | null): void {
    pending = next;
    listeners.forEach((listener) => listener());
}

/** Records a tab tap from the bottom nav or the history toggle; the tab turns pending only once Inertia starts that tab's visit. */
export function armPendingTab(tab: TabId, from: string): void {
    armed = { tab, from };
}

export function subscribePendingTab(listener: () => void): () => void {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}

export function pendingTabSnapshot(): PendingTab | null {
    return pending;
}

function pendingTarget(
    path: string,
    tapped: Tap | null,
    from: string,
    pushedOrPopped: string | null,
): Target | null {
    if (pushedOrPopped !== null) {
        const tab = navTabFor(pushedOrPopped);

        return tab === null
            ? { tab, screen: pushedOrPopped, from }
            : { tab, screen: null, from };
    }

    const tabPath = ITEMS.find((item) => item.id === tapped?.tab)?.href;
    if (tapped !== null && path === tabPath) {
        return { ...tapped, screen: null };
    }

    return path === RACE_PATH && from !== 'Race'
        ? { tab: RACE_TAB, screen: null, from }
        : null;
}

function show(next: PendingTab, direction: NavDirection | null): void {
    if (direction === null || prefersReducedMotion()) {
        setPending(next);
        return;
    }

    const slide = {
        visit: next.visit,
        transition: null as ViewTransition | null,
    };
    sliding = slide;
    slide.transition = startSlide(direction, () => {
        if (sliding === slide) {
            setPending(next);
        }
    });
}

function startedTabVisit(
    visit: PendingVisit,
    currentComponent: () => string,
): void {
    if (visit.async || visit.prefetch) {
        return;
    }

    const tapped = armed;
    armed = null;
    if (visit.method !== 'get') {
        return;
    }

    const path = visit.url.pathname;
    const from = tapped?.from ?? currentComponent();
    const screen = screenForPath(path);
    const direction = slidesScreens() ? navDirection(from, screen) : null;
    const target = pendingTarget(
        path,
        tapped,
        from,
        direction === null ? null : screen,
    );
    if (target === null) {
        return;
    }

    show(
        {
            ...target,
            href: path + visit.url.search,
            scrollY: window.scrollY,
            visit,
        },
        direction,
    );
}

function finishedVisit(visit: PendingVisit, completed: boolean): void {
    if (sliding !== null && sliding.visit === visit) {
        if (!completed) {
            sliding.transition?.skipTransition();
        }
        sliding = null;
    }

    if (pending !== null && pending.visit === visit) {
        setPending(null);
    }
}

/** Follows Inertia's visit lifecycle; returns the unsubscribe. */
export function trackTabVisits(currentComponent: () => string): () => void {
    const offStart = router.on('start', (event) => {
        startedTabVisit(event.detail.visit, currentComponent);
    });
    const offFinish = router.on('finish', (event) => {
        finishedVisit(event.detail.visit, event.detail.visit.completed);
    });

    return () => {
        offStart();
        offFinish();
        armed = null;
        sliding = null;
        if (pending !== null) {
            setPending(null);
        }
    };
}
