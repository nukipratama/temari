let screen = '';
let requested = false;

/**
 * Loads the phone's push slide (lib/pushStack) once the browser is idle,
 * unless Data Saver is on. Only the coarse-pointer pull-to-refresh gesture
 * calls it, so a desktop never loads the chunk. `component` is the page on
 * screen, kept current for the slide.
 */
export function warmPushStack(component: string): void {
    screen = component;
    // `connection` is still not in lib.dom.
    const connection = (
        navigator as Navigator & { connection?: { saveData?: boolean } }
    ).connection;
    if (requested || connection?.saveData) {
        return;
    }

    requested = true;
    const load = () => {
        void import('@/lib/pushStack')
            .then(({ startPushStack }) => startPushStack(() => screen))
            .catch(() => undefined);
    };
    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(load, { timeout: 5000 });
    } else {
        window.setTimeout(load, 2000);
    }
}
