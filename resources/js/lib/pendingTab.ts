import type { PendingVisit } from '@inertiajs/core';
import type { ComponentType } from 'react';

import { router } from '@inertiajs/react';

import type { ContextualOrigin } from '@/lib/nav';
import type { TabId } from '@/lib/navRoutes';

import { ITEMS } from '@/lib/nav';
import { RACE_PATH, RACE_TAB } from '@/lib/navRoutes';

/** A pushed screen a phone slid to (lib/pushStack), with the origin its back chevron returns to. */
export interface PushedScreen {
    screen: string;
    origin: ContextualOrigin | null;
    Skeleton: ComponentType;
}

export type PendingTab = (
    { tab: TabId; pushed?: undefined } | { tab: null; pushed: PushedScreen }
) & {
    /** The page component the visit started from. */
    from: string;
    href: string;
    /** The scroll position on the page the visit started from. */
    scrollY: number;
    visit: PendingVisit;
};

let armed: { tab: TabId; from: string } | null = null;
let pending: PendingTab | null = null;
const listeners = new Set<() => void>();

/** Sets what `main` shows while a visit is in flight; lib/pushStack sets pushes and pops. */
export function showPending(next: PendingTab | null): void {
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
    tapped: { tab: TabId; from: string } | null,
    currentComponent: () => string,
): { tab: TabId; from: string } | null {
    const tabPath = ITEMS.find((item) => item.id === tapped?.tab)?.href;
    if (tapped !== null && path === tabPath) {
        return tapped;
    }

    const from = tapped?.from ?? currentComponent();

    return path === RACE_PATH && from !== 'Race'
        ? { tab: RACE_TAB, from }
        : null;
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
    const target = pendingTarget(path, tapped, currentComponent);
    if (target === null) {
        return;
    }

    showPending({
        ...target,
        href: path + visit.url.search,
        scrollY: window.scrollY,
        visit,
    });
}

/** Follows Inertia's visit lifecycle; returns the unsubscribe. */
export function trackTabVisits(currentComponent: () => string): () => void {
    const offStart = router.on('start', (event) => {
        startedTabVisit(event.detail.visit, currentComponent);
    });
    const offFinish = router.on('finish', (event) => {
        if (pending !== null && pending.visit === event.detail.visit) {
            showPending(null);
        }
    });

    return () => {
        offStart();
        offFinish();
        armed = null;
        if (pending !== null) {
            showPending(null);
        }
    };
}
