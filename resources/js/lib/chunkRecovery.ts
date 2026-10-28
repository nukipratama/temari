/**
 * After a deploy, an open app still references the previous build's
 * content-hashed chunks, which no longer exist. Vite reports the failed import
 * as `vite:preloadError`; a full load of the intended URL fetches the new
 * build. A reload within the last few seconds means the new build is failing
 * too, so the error is left to surface rather than looping.
 */
const RELOADED_AT_KEY = 'temari-chunk-reload-at';
const LOOP_WINDOW_MS = 10_000;

export function recoverFromStaleChunks(reload: () => void): void {
    window.addEventListener('vite:preloadError', (event) => {
        const last = Number(sessionStorage.getItem(RELOADED_AT_KEY));
        if (Date.now() - last < LOOP_WINDOW_MS) {
            return;
        }

        sessionStorage.setItem(RELOADED_AT_KEY, String(Date.now()));
        event.preventDefault();
        reload();
    });
}
