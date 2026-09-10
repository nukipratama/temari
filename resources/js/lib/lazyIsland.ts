import type { ComponentType } from 'react';

import { lazy } from 'react';

const RELOADED_KEY = 'temari-chunk-reload';

/**
 * A lazy chunk that 404s after a deploy cannot self-heal the way a navigation
 * does: Inertia's asset-version header only fires on a *visit*, and an island
 * inside an already-rendered page never makes one. Left alone the rejected
 * import reaches the app-wide ErrorBoundary and the whole screen turns into
 * the crash fallback. A full document reload picks up the new manifest.
 *
 * The session flag makes that reload happen at most once, so a chunk that is
 * genuinely broken shows the crash fallback instead of looping. It is never
 * cleared: the price is that a second, unrelated chunk failure in the same
 * session falls through to the boundary.
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

    window.location.reload();

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
