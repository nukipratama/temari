import { useSyncExternalStore } from 'react';

const QUERY = '(pointer: coarse)';

function media(): MediaQueryList | undefined {
    return globalThis.matchMedia?.(QUERY);
}

function subscribe(onChange: () => void): () => void {
    const list = media();
    if (list === undefined) {
        return () => {};
    }
    list.addEventListener('change', onChange);
    return () => list.removeEventListener('change', onChange);
}

/** Coarse where the query is unavailable: that keeps the native control, which always works. */
function snapshot(): boolean {
    return media()?.matches ?? true;
}

/**
 * Whether the primary pointer is coarse — a finger rather than a mouse.
 *
 * `useSyncExternalStore` rather than state-plus-effect, so the value is read
 * during render and a touch device never paints a pointer-only affordance and
 * then removes it, and so a change landing between render and subscription
 * cannot be missed.
 */
export function useCoarsePointer(): boolean {
    return useSyncExternalStore(subscribe, snapshot, () => true);
}
