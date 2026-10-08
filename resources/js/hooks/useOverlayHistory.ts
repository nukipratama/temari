import type { router as inertiaRouter } from '@inertiajs/react';

import { useEffect, useEffectEvent } from 'react';

interface Entry {
    close: () => void;
    closed: boolean;
}

/** One history entry per open overlay, in the order they were pushed. */
const entries: Entry[] = [];
let pendingPops = 0;
let stateBeforePop: unknown = null;
/** The page's latest history state while an overlay entry sits on top of it. */
let pageState: unknown = null;

function pop(count: number): void {
    pendingPops++;
    stateBeforePop = window.history.state;
    window.history.go(-count);
}

function popClosedEntries(): void {
    let count = 0;
    while (entries.at(-1)?.closed === true) {
        entries.pop();
        count++;
    }
    if (count > 0) {
        pop(count);
    }
}

function onPopState(event: PopStateEvent): void {
    if (pendingPops > 0) {
        pendingPops--;
        event.stopImmediatePropagation();
        // Inertia may have rewritten the overlay's entry (a same-URL visit), so the page underneath keeps that state.
        window.history.replaceState(stateBeforePop, '');
        return;
    }

    const top = entries.pop();
    if (top === undefined) {
        return;
    }
    event.stopImmediatePropagation();
    window.history.replaceState(pageState, '');
    top.close();
    popClosedEntries();
}

function closeAll(): number {
    const open = entries.filter((entry) => !entry.closed).reverse();
    const count = entries.length;
    entries.length = 0;
    open.forEach((entry) => entry.close());
    return count;
}

function onVisitStart(event: {
    detail: { visit: { preserveState: unknown; prefetch: boolean } };
}): void {
    const { visit } = event.detail;
    if (entries.length === 0 || visit.preserveState || visit.prefetch) {
        return;
    }

    pop(closeAll());
}

function onBeforeUpdate(event: { detail: { page: { url: string } } }): void {
    if (entries.length === 0) {
        return;
    }

    const next = new URL(event.detail.page.url, window.location.href);
    const here = new URL(window.location.href);
    next.hash = '';
    here.hash = '';
    if (next.href !== here.href) {
        // Inertia pushes this page above the overlay entries, which can no longer be popped from under it.
        closeAll();
    }
}

function onVisitSuccess(): void {
    if (entries.length > 0) {
        pageState = window.history.state;
    }
}

/**
 * Must run before Inertia boots: popstate listeners on window fire in
 * registration order, and only an earlier one can keep Inertia from
 * re-rendering the page for a Back that only closed an overlay.
 */
export function installOverlayHistory(router: typeof inertiaRouter): void {
    window.addEventListener('popstate', onPopState);
    router.on('start', onVisitStart);
    router.on('beforeUpdate', onBeforeUpdate);
    router.on('success', onVisitSuccess);
}

/**
 * Gives an open overlay its own history entry, so Android/browser Back closes
 * the topmost overlay instead of leaving the page. Closing any other way pops
 * the entry again, and a page visit that does not preserve state takes every
 * overlay's entry with it before the new page is pushed.
 */
export function useOverlayHistory(open: boolean, onClose: () => void): void {
    const close = useEffectEvent(onClose);

    useEffect(() => {
        if (!open) {
            return;
        }

        const entry: Entry = { close: () => close(), closed: false };
        pageState = window.history.state;
        window.history.pushState(window.history.state, '');
        entries.push(entry);

        return () => {
            if (entries.includes(entry)) {
                entry.closed = true;
                popClosedEntries();
            }
        };
    }, [open]);
}
