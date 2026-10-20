import type { ComponentType } from 'react';

import { lazy } from 'react';

import { reloadDocument } from '@/lib/reloadDocument';

const RELOADED_KEY = 'temari-chunk-reload';

/**
 * Reloads once when a lazy chunk 404s after a deploy: an island inside an
 * already-rendered page never makes the visit Inertia's asset-version check
 * needs, so the rejected import would otherwise crash the whole screen.
 *
 * The session flag makes the reload happen at most once, so a genuinely broken
 * chunk shows the crash fallback instead of looping. It is never cleared.
 *
 * Returns whether a reload was started.
 */
export function recoverFromChunkFailure(): boolean {
    try {
        if (window.sessionStorage.getItem(RELOADED_KEY) !== null) return false;
        window.sessionStorage.setItem(RELOADED_KEY, '1');
    } catch {
        return false;
    }

    reloadDocument();

    return true;
}

/**
 * `lazy()` for a piece of an already-rendered page. Use it for every lazy
 * boundary that is not an Inertia page, and give it a fallback that reserves
 * the space the real thing will take.
 */
// React's own `lazy` is typed this way; narrowing it stops props inferring.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function lazyIsland<T extends ComponentType<any>>(
    load: () => Promise<{ default: T }>,
) {
    return lazy(() =>
        load().catch((error: unknown) => {
            if (recoverFromChunkFailure()) {
                // The document is being replaced; never settle.
                return new Promise<{ default: T }>(() => {});
            }

            throw error;
        }),
    );
}
