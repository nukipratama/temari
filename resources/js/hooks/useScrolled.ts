import { useCallback, useSyncExternalStore } from 'react';

function subscribe(onChange: () => void): () => void {
    window.addEventListener('scroll', onChange, { passive: true });
    return () => {
        window.removeEventListener('scroll', onChange);
    };
}

/**
 * True once the document has scrolled past `threshold` pixels.
 *
 * Drives the hairline under the sticky mobile header: a border that is always
 * on reads as a drawn box, while one that appears only when content is
 * actually sliding underneath reads as the iOS navigation bar it imitates.
 *
 * Scroll offset is browser state rather than React state, so it is read
 * through useSyncExternalStore: the first paint already sees the real offset
 * (a restored scroll position does not need a second render to catch up).
 */
export function useScrolled(threshold = 4): boolean {
    const getSnapshot = useCallback(() => window.scrollY > threshold, [threshold]);

    return useSyncExternalStore(subscribe, getSnapshot, () => false);
}
