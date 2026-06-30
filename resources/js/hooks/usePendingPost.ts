import { useState } from 'react';
import { router } from '@inertiajs/react';
import type { VisitOptions } from '@inertiajs/core';

/**
 * Returns a `[pending, post]` pair. `post` wraps `router.post` and flips
 * `pending` to true for the duration of the request so the caller can disable
 * its button and show a loading label without managing the flag manually.
 */
export function usePendingPost(url: string, options?: Omit<VisitOptions, 'onStart' | 'onFinish'>): [boolean, () => void] {
    const [pending, setPending] = useState(false);
    const post = () =>
        router.post(url, {}, {
            ...options,
            onStart: () => setPending(true),
            onFinish: () => setPending(false),
        });
    return [pending, post];
}
