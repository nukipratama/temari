/**
 * After a deploy, an open app still references the previous build's
 * content-hashed chunks, which no longer exist. Vite reports the failed import
 * as `vite:preloadError`; a full load of the intended URL fetches the new
 * build. Only a failure during a page visit is recovered: `reload` returns false
 * when no visit is in flight, such as an idle prefetch, whose importer handles
 * its own error. Vite fires once per failed dependency, so every event after the first
 * is swallowed while that reload is under way. A reload within the last few
 * seconds means the new build is failing too, so the error is left to surface
 * rather than looping.
 */
const RELOADED_AT_KEY = 'temari-chunk-reload-at';
const LOOP_WINDOW_MS = 10_000;

export function recoverFromStaleChunks(reload: () => boolean): () => void {
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

        if (!reload()) {
            return;
        }

        sessionStorage.setItem(RELOADED_AT_KEY, String(Date.now()));
        reloading = true;
        event.preventDefault();
    };

    window.addEventListener('vite:preloadError', onPreloadError);

    return () =>
        window.removeEventListener('vite:preloadError', onPreloadError);
}
