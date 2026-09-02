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
 * Chart.js reads plain JS values, not CSS custom properties, so a chart's
 * colour choices can't just follow the `[data-theme]` cascade the way a
 * component's classes do — they need to know which ground is active and
 * recompute. `data-theme` only ever changes via `useSystemTheme`'s OS
 * listener today (there is no manual toggle yet — that's S11), but a
 * MutationObserver keeps this correct however it ends up changing.
 */
export function useIsChartDark(): boolean {
    return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
