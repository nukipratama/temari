import type { VisitOptions } from '@inertiajs/core';

import { router } from '@inertiajs/react';
import { useState } from 'react';

import { hapticCommit } from '@/lib/haptics';

/**
 * Returns a `[pending, post]` pair. `post` wraps `router.post` and flips
 * `pending` to true for the duration of the request so the caller can disable
 * its button and show a loading label.
 *
 * A successful round trip also fires {@see hapticCommit}, the physical
 * counterpart to the flash message; it hangs off `onSuccess` so a failed post
 * never buzzes, and a caller's own `onSuccess` still runs.
 */
export function usePendingPost(
    url: string,
    options?: Omit<VisitOptions, 'onStart' | 'onFinish'>,
): [boolean, () => void] {
    const [pending, setPending] = useState(false);
    const post = () =>
        router.post(
            url,
            {},
            {
                ...options,
                onStart: () => setPending(true),
                onSuccess: (page) => {
                    hapticCommit();
                    options?.onSuccess?.(page);
                },
                onFinish: () => setPending(false),
            },
        );
    return [pending, post];
}
