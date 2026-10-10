import type { PendingVisit } from '@inertiajs/core';

import { router } from '@inertiajs/react';

import type { TabId } from '@/lib/navRoutes';

import { ITEMS } from '@/lib/nav';

export interface PendingTab {
    tab: TabId;
    /** The page component the tab was tapped from. */
    from: string;
    href: string;
    /** The scroll position on the page the tab was tapped from. */
    scrollY: number;
    visit: PendingVisit;
}

let armed: Pick<PendingTab, 'tab' | 'from'> | null = null;
let pending: PendingTab | null = null;
const listeners = new Set<() => void>();

function setPending(next: PendingTab | null): void {
    pending = next;
    listeners.forEach((listener) => listener());
}

/** Records a bottom-nav tap; the tab turns pending only once Inertia starts that tab's visit. */
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

function startedTabVisit(visit: PendingVisit): void {
    if (visit.async || visit.prefetch) {
        return;
    }

    const tapped = armed;
    armed = null;
    const tabPath = ITEMS.find((item) => item.id === tapped?.tab)?.href;
    if (
        tapped === null ||
        visit.method !== 'get' ||
        visit.url.pathname !== tabPath
    ) {
        return;
    }

    setPending({
        ...tapped,
        href: visit.url.pathname + visit.url.search,
        scrollY: window.scrollY,
        visit,
    });
}

/** Follows Inertia's visit lifecycle; returns the unsubscribe. */
export function trackTabVisits(): () => void {
    const offStart = router.on('start', (event) => {
        startedTabVisit(event.detail.visit);
    });
    const offFinish = router.on('finish', (event) => {
        if (pending !== null && pending.visit === event.detail.visit) {
            setPending(null);
        }
    });

    return () => {
        offStart();
        offFinish();
    };
}
