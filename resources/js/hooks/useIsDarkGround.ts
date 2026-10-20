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

// Dark is the app's default ground (decision 6) — matches the fallback in
// app.blade.php's blocking script for the same "resolve before we can know
// for sure" moment.
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
