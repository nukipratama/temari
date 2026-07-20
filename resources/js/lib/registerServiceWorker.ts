/**
 * Register the service worker for every visitor on app load.
 *
 * It used to register lazily, only when a user opted into push, which meant the
 * offline fallback existed only for people who had enabled notifications. The
 * worker's other job (turning a push into a notification) is unaffected by
 * registering earlier — {@see subscribe} reuses whatever registration is already
 * there.
 *
 * Deliberately fire-and-forget: nothing in the UI depends on this succeeding, so
 * a browser without service workers, a non-secure origin, or a blocked
 * registration all degrade silently to the previous behaviour.
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
            void navigator.serviceWorker.register('/sw.js').catch(() => {
                // Insecure origin, private mode, or a policy block. Nothing to do.
            });
        },
        { once: true },
    );
}
