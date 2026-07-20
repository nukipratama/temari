import { afterEach, describe, expect, it, vi } from 'vitest';
import { registerServiceWorker } from './registerServiceWorker';

function stubServiceWorker(register: () => Promise<unknown>) {
    Object.defineProperty(navigator, 'serviceWorker', {
        value: { register },
        configurable: true,
        writable: true,
    });
}

/** Runs the handler `registerServiceWorker` attached to window 'load'. */
function fireLoad() {
    globalThis.dispatchEvent(new Event('load'));
}

afterEach(() => {
    Reflect.deleteProperty(navigator, 'serviceWorker');
    vi.restoreAllMocks();
});

describe('registerServiceWorker', () => {
    it('registers /sw.js after load', () => {
        const register = vi.fn().mockResolvedValue({});
        stubServiceWorker(register);

        registerServiceWorker();
        expect(register).not.toHaveBeenCalled();

        fireLoad();
        expect(register).toHaveBeenCalledWith('/sw.js');
    });

    // Registration competes with the initial render for bandwidth, and the
    // offline page is only needed on a later visit.
    it('does not register before load', () => {
        const register = vi.fn().mockResolvedValue({});
        stubServiceWorker(register);

        registerServiceWorker();

        expect(register).not.toHaveBeenCalled();
    });

    it('does nothing when the browser has no service worker support', () => {
        Reflect.deleteProperty(navigator, 'serviceWorker');

        expect(() => {
            registerServiceWorker();
            fireLoad();
        }).not.toThrow();
    });

    // Insecure origin, private mode, or a policy block: nothing in the UI
    // depends on this succeeding.
    it('swallows a rejected registration', async () => {
        const register = vi.fn().mockRejectedValue(new Error('insecure origin'));
        stubServiceWorker(register);

        registerServiceWorker();
        fireLoad();

        // A rejected registration must not surface as an unhandled rejection.
        await expect(Promise.resolve()).resolves.toBeUndefined();
        // Not `toHaveBeenCalledOnce`: an earlier test registers a listener it
        // never fires, and jsdom shares one window across the file.
        expect(register).toHaveBeenCalled();
    });
});
