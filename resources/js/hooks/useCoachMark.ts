import { usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';

import type { SharedProps } from '@/types/inertia';

const STORAGE_PREFIX = 'temari:coachmark:';

function storageKey(userId: number | undefined, id: string): string {
    return `${STORAGE_PREFIX}${userId ?? 'anon'}:${id}`;
}

function readDismissed(key: string): boolean {
    try {
        return window.localStorage.getItem(key) === '1';
    } catch {
        return false;
    }
}

/**
 * Tracks whether a coach-mark identified by `id` has been dismissed, scoped
 * per signed-in user so a shared/demo browser doesn't leak one account's
 * dismissals into another's session.
 */
export function useCoachMark(id: string) {
    const userId = usePage<SharedProps>().props.auth.user?.id;
    const key = storageKey(userId, id);
    const [dismissed, setDismissed] = useState(() => readDismissed(key));

    const dismiss = useCallback(() => {
        setDismissed(true);
        try {
            window.localStorage.setItem(key, '1');
        } catch {
            // Private mode / quota / disabled storage: it just reappears next visit.
        }
    }, [key]);

    return { visible: !dismissed, dismiss };
}
