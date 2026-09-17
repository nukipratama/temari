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
 * Some consumers read plain JS values, not CSS custom properties, so they
 * can't just follow the `[data-theme]` cascade the way a component's
 * classes do — they need to know which ground is active and recompute.
 * Chart.js colour picks and Leaflet tile URLs are both this shape.
 * `data-theme` can change via `useTheme`'s explicit toggle or
 * `useSystemTheme`'s OS listener; a MutationObserver keeps this correct
 * however it ends up changing.
 */
export function useIsDarkGround(): boolean {
    return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
