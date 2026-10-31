import type { ComponentProps, ComponentType } from 'react';

import { createElement, lazy } from 'react';

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
 *
 * Once the module has loaded, the island renders it directly instead of
 * through `lazy()`, so a warm chunk never suspends and React 19 never holds a
 * fallback for it. `preload()` loads the module ahead of the first render.
 */
// React's own `lazy` is typed this way; narrowing it stops props inferring.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function lazyIsland<T extends ComponentType<any>>(
    load: () => Promise<{ default: T }>,
) {
    let loaded: T | null = null;

    const remember = (module: { default: T }) => {
        loaded = module.default;

        return module;
    };

    const Lazy = lazy(() =>
        load()
            .then(remember)
            .catch((error: unknown) => {
                if (recoverFromChunkFailure()) {
                    // The document is being replaced; never settle.
                    return new Promise<{ default: T }>(() => {});
                }

                throw error;
            }),
    );

    function Island(props: ComponentProps<T>) {
        return createElement(loaded ?? Lazy, props);
    }

    Island.preload = (): void => {
        load().then(remember, () => {});
    };

    return Island;
}
