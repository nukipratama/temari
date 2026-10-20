import { useSyncExternalStore } from 'react';

function subscribe(onChange: () => void): () => void {
    const observer = new MutationObserver(onChange);
    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme'],
    });
    return () => observer.disconnect();
}

function getSnapshot(): boolean {
    return document.documentElement.dataset.theme === 'dark';
}

function getServerSnapshot(): boolean {
    return true;
}

/**
 * Whether the dark ground is active, for consumers that read plain JS values
 * rather than CSS custom properties (Chart.js colour picks). A MutationObserver
 * on `data-theme` keeps it correct however the ground changes (`useTheme`'s
 * toggle or `useSystemTheme`'s OS listener).
 */
export function useIsDarkGround(): boolean {
    return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
