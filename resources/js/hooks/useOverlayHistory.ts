import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

interface Entry {
    close: () => void;
    closed: boolean;
}

/** One history entry per open overlay, in the order they were pushed. */
const entries: Entry[] = [];
let pendingPops = 0;
let stateBeforePop: unknown = null;

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
    top.close();
    popClosedEntries();
}

function onVisitStart(event: {
    detail: { visit: { preserveState: unknown; prefetch: boolean } };
}): void {
    const { visit } = event.detail;
    if (entries.length === 0 || visit.preserveState || visit.prefetch) {
        return;
    }

    const open = entries.filter((entry) => !entry.closed).reverse();
    const count = entries.length;
    entries.length = 0;
    pop(count);
    open.forEach((entry) => entry.close());
}

/**
 * Must run before Inertia boots: popstate listeners on window fire in
 * registration order, and only an earlier one can keep Inertia from
 * re-rendering the page for a Back that only closed an overlay.
 */
export function installOverlayHistory(): void {
    window.addEventListener('popstate', onPopState);
    router.on('start', onVisitStart);
}

/**
 * Gives an open overlay its own history entry, so Android/browser Back closes
 * the topmost overlay instead of leaving the page. Closing any other way pops
 * the entry again, and a page visit that does not preserve state takes every
 * overlay's entry with it before the new page is pushed.
 */
export function useOverlayHistory(open: boolean, onClose: () => void): void {
    const closeRef = useRef(onClose);

    useEffect(() => {
        closeRef.current = onClose;
    });

    useEffect(() => {
        if (!open) {
            return;
        }

        const entry: Entry = { close: () => closeRef.current(), closed: false };
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
