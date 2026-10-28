/**
 * After a deploy, an open app still references the previous build's
 * content-hashed chunks, which no longer exist. Vite reports the failed import
 * as `vite:preloadError`; a full load of the intended URL fetches the new
 * build. Vite fires once per failed dependency, so every event after the first
 * is swallowed while that reload is under way. A reload within the last few
 * seconds means the new build is failing too, so the error is left to surface
 * rather than looping.
 */
const RELOADED_AT_KEY = 'temari-chunk-reload-at';
const LOOP_WINDOW_MS = 10_000;

export function recoverFromStaleChunks(reload: () => void): () => void {
    let reloading = false;

    const onPreloadError = (event: Event): void => {
        if (reloading) {
            event.preventDefault();
            return;
        }

        const last = Number(sessionStorage.getItem(RELOADED_AT_KEY));
        if (Date.now() - last < LOOP_WINDOW_MS) {
            return;
        }

        sessionStorage.setItem(RELOADED_AT_KEY, String(Date.now()));
        reloading = true;
        event.preventDefault();
        reload();
    };

    window.addEventListener('vite:preloadError', onPreloadError);

    return () =>
        window.removeEventListener('vite:preloadError', onPreloadError);
}
