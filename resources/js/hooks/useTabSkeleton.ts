import {
    useEffect,
    useLayoutEffect,
    useRef,
    useSyncExternalStore,
} from 'react';

import type { PendingTab } from '@/lib/pendingTab';

import {
    pendingTabSnapshot,
    subscribePendingTab,
    trackTabVisits,
} from '@/lib/pendingTab';

/**
 * The tab whose skeleton should stand in for `component`, the page on screen,
 * or null. Scrolls to the top while it shows, and back to where the reader was
 * if the visit ends without leaving the page.
 */
export default function useTabSkeleton(component: string): PendingTab | null {
    const pending = useSyncExternalStore(
        subscribePendingTab,
        pendingTabSnapshot,
        () => null,
    );
    const skeleton =
        pending !== null && pending.from === component ? pending : null;
    const shown = skeleton !== null;
    const scrollYAtTap = skeleton?.scrollY ?? 0;
    const leftFromRef = useRef<{ component: string; scrollY: number } | null>(
        null,
    );

    useEffect(() => trackTabVisits(), []);

    useLayoutEffect(() => {
        if (shown) {
            leftFromRef.current ??= { component, scrollY: scrollYAtTap };
            window.scrollTo(0, 0);
            return;
        }

        const left = leftFromRef.current;
        leftFromRef.current = null;
        if (left !== null && left.component === component) {
            window.scrollTo(0, left.scrollY);
        }
    }, [shown, component, scrollYAtTap]);

    return skeleton;
}
