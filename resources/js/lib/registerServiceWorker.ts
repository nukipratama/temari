/**
 * Register the service worker for every visitor on app load, so the offline
 * fallback works regardless of push-notification opt-in. {@see subscribe}
 * reuses whatever registration is already there.
 *
 * Fire-and-forget: nothing in the UI depends on it succeeding, so any failure
 * degrades silently.
 */
export function registerServiceWorker(): void {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    // After load: registration competes with the initial render for bandwidth,
    // and the offline page is only needed on a *later* visit anyway. `once` so a
    // stray second `load` can't re-register.
    globalThis.addEventListener(
        'load',
        () => {
            // Re-checked rather than relying on the guard above: the handler runs
            // later, and nothing guarantees the API is still there by then.
            if (!('serviceWorker' in navigator)) {
                return;
            }

            void navigator.serviceWorker.register('/sw.js').catch(() => {
                // Insecure origin, private mode, or a policy block. Nothing to do.
            });
        },
        { once: true },
    );
}
